<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\ConfigRegistry;
use App\Entity\Attachment;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\Tracker;
use App\Entity\User;
use App\Form\Issue\IssueData;
use App\Form\Issue\IssueType;
use App\Message\Command\Issue\CreateIssue;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListProjectTeams;
use App\Message\Query\Project\ListTrackers;
use App\Message\QueryBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\MessageHandler\Command\Tag\TagException;
use App\Repository\AttachmentRepository;
use App\Repository\TagRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\HandlesAttachments;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The new issue screen in one page: the tracker first, then the form built
 * for its workflow and for what the viewer may qualify. A valid submission
 * is a CreateIssue command, then a redirect to the new issue, or back to
 * this page as it opens for whoever has more to write.
 */
#[AsLiveComponent('Issue:CreateForm')]
final class CreateForm extends AbstractController
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;
    use HandlesAttachments;

    #[LiveProp]
    public Project $project;

    #[LiveProp(writable: true, onUpdated: 'onTrackerChanged')]
    public ?int $trackerId = null;

    /** A refusal of the command, shown above the form. */
    public ?string $failure = null;

    /** @var list<string> uuids of the files sent for the new issue, pending until it is created */
    #[LiveProp]
    public array $pending = [];

    /** Why the last file was refused. */
    public ?string $attachError = null;

    public function __construct(
        private readonly ConfigRegistry $config,
        private readonly QueryBus $queries,
        private readonly FormFactoryInterface $forms,
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly AttachmentRepository $attachments,
        private readonly TagRepository $tags,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The trackers the viewer may file into: private ones only when internal.
     *
     * @return list<Tracker>
     */
    public function getTrackers(): array
    {
        return $this->queries->ask(new ListTrackers($this->viewer->get(), $this->project));
    }

    /**
     * The chosen tracker, if it is one of those offered; a forged id is ignored.
     */
    public function getTracker(): ?Tracker
    {
        foreach ($this->getTrackers() as $tracker) {
            if ($tracker->getId() === $this->trackerId) {
                return $tracker;
            }
        }

        return null;
    }

    /**
     * Changing the tracker changes the form: start it afresh.
     */
    public function onTrackerChanged(): void
    {
        $this->resetForm();
    }

    /**
     * The files sent for the new issue, still pending.
     *
     * @return list<Attachment>
     */
    public function getPendingFiles(): array
    {
        if ([] === $this->pending) {
            return [];
        }

        return $this->attachments->findUnattached($this->project, $this->viewer->get(), array_map(Uuid::fromString(...), $this->pending));
    }

    /** Files chosen for the new issue: sent now, pending until it is created. */
    #[LiveAction]
    public function attach(Request $request, CommandBus $commands): void
    {
        [$sent, $this->attachError] = $this->sendFiles($request, $commands, $this->viewer->get(), $this->project);
        foreach ($sent as $attachment) {
            $this->pending[] = $attachment->getUuid()->toRfc4122();
        }
    }

    /** Takes a file back from the new issue; it stays pending, unused. */
    #[LiveAction]
    public function discard(#[LiveArg] string $uuid): void
    {
        $this->pending = array_values(array_diff($this->pending, [$uuid]));
    }

    #[LiveAction]
    public function save(CommandBus $commands, TranslatorInterface $translator, #[LiveArg] bool $continue = false): ?Response
    {
        $tracker = $this->getTracker();
        if (null === $tracker) {
            return null;
        }

        $this->submitForm();
        $data = $this->getForm()->getData();
        \assert($data instanceof IssueData);
        $viewer = $this->viewer->get();

        try {
            $issue = $commands->dispatch(new CreateIssue(
                actorId: (int) $viewer->getId(),
                projectId: (int) $this->project->getId(),
                trackerId: (int) $tracker->getId(),
                title: (string) $data->title,
                description: (string) $data->description,
                criticality: $data->criticality,
                assigneeId: $data->assignee instanceof User ? $data->assignee->getId() : null,
                private: $data->private,
                fields: array_filter($data->fields, static fn (mixed $value): bool => null !== $value && '' !== $value),
                attachments: $this->pending,
                tagIds: array_map(static fn (Tag $tag): int => (int) $tag->getId(), $data->tags),
                assigneeTeamId: $data->assignee instanceof Team ? $data->assignee->getId() : null,
            ));
        } catch (IssueException|TagException) {
            // A tag deleted while the issue was being written is a refusal like another.
            $this->failure = $translator->trans('issue.create.refused');

            return null;
        }
        \assert($issue instanceof Issue);

        $this->addFlash('success', $translator->trans('issue.create.created', ['%key%' => $issue->getKey()]));

        // "Create and continue": back to the page as it opens, tracker to choose.
        if ($continue) {
            return $this->redirectToRoute('app_issue_new', [
                'workspaceKey' => $this->project->getWorkspace()->getKey(),
                'projectKey' => $this->project->getKey(),
            ]);
        }

        return $this->redirectToRoute('app_issue_show', [
            'workspaceKey' => $this->project->getWorkspace()->getKey(),
            'projectKey' => $this->project->getKey(),
            'number' => $issue->getNumber(),
        ]);
    }

    /**
     * Without a tracker there is nothing to fill in yet, but the trait
     * always wants a form: an empty one, under the same name. The form
     * only ever travels through the live endpoint, whose required custom
     * header already keeps cross-origin requests out, so it carries no
     * CSRF token of its own.
     *
     * @return FormInterface<mixed>
     */
    protected function instantiateForm(): FormInterface
    {
        $tracker = $this->getTracker();
        if (null === $tracker) {
            return $this->forms->createNamed('issue', FormType::class, null, ['csrf_protection' => false]);
        }

        $viewer = $this->viewer->get();
        $definition = $this->config->definition();
        $assigns = $this->access->isGranted($viewer, Permission::IssueAssign, $this->project);

        return $this->forms->createNamed('issue', IssueType::class, new IssueData(), [
            'workflow' => $definition->workflow($tracker->getWorkflowId()),
            'definition' => $definition,
            'internal' => $viewer->isInternal(),
            'criticality' => $this->access->isGranted($viewer, Permission::IssueSetCriticality, $this->project),
            'members' => $assigns ? $this->queries->ask(new ListProjectMembers($this->project)) : null,
            'teams' => $assigns ? $this->queries->ask(new ListProjectTeams($this->project)) : [],
            'teams_label' => $this->translator->trans('issue.field.assignee_teams'),
            'tags' => $this->access->isGranted($viewer, Permission::IssueSetTags, $this->project) ? $this->tags->findByProject($this->project) : [],
            // Issues of a private tracker are private whatever one chooses.
            'private' => !$tracker->isPrivate() && $this->access->isGranted($viewer, Permission::IssueSetPrivate, $this->project),
            'project' => $this->project,
            'csrf_protection' => false,
        ]);
    }

    /**
     * Field values are kept in sync without re-rendering: nothing on the
     * form depends on them, only on the tracker.
     */
    private function getDataModelValue(): string
    {
        return 'norender|*';
    }
}
