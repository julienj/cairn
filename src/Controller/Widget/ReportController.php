<?php

declare(strict_types=1);

namespace App\Controller\Widget;

use App\Config\ConfigRegistry;
use App\Controller\Project\CurrentProject;
use App\Entity\Attachment;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Form\Widget\ReportData;
use App\Form\Widget\ReportType;
use App\Message\Command\Attachment\UploadAttachment;
use App\Message\Command\Issue\CreateIssue;
use App\Message\CommandBus;
use App\Message\Query\Project\ListTrackers;
use App\Message\QueryBus;
use App\MessageHandler\Command\Issue\IssueException;
use App\MessageHandler\Command\Tag\TagException;
use App\Repository\TagRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Widget\TechnicalReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the widget does for whoever linked their account to it: the form
 * that reports a problem, in the `widget-report` Turbo frame of the
 * panel, the files and the screenshot sent while it is filled, the
 * report of the site as it will be kept, and the word that the issue was
 * created. Every request carries the widget token, which names the user;
 * what they may do is what their role says, as in the application.
 */
#[Route('/widget/{workspaceKey}/{projectKey}', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
#[IsGranted('issue.create', subject: 'project')]
final class ReportController extends AbstractController
{
    public function __construct(
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly ConfigRegistry $config,
        private readonly AccessControl $access,
        private readonly TagRepository $tags,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The form, drawn for one category: the first one, or the one chosen.
     * Changing the category sends the form back with `refresh`, to be
     * drawn again for the other one with what was typed, creating nothing.
     */
    #[Route('/report', name: 'app_widget_report', methods: ['GET', 'POST'])]
    public function report(#[CurrentProject] Project $project, #[CurrentUser] User $reporter, Request $request): Response
    {
        $trackers = array_values(array_filter($this->queries->ask(new ListTrackers($reporter, $project)), static fn (Tracker $tracker): bool => !$tracker->isArchived()));
        if ([] === $trackers) {
            throw new NotFoundHttpException(\sprintf('%s has no category to report into.', $project->getIssuePrefix()));
        }
        $asked = $request->request->all('report')['tracker'] ?? null;
        $tracker = array_find($trackers, static fn (Tracker $tracker): bool => (string) $tracker->getId() === $asked) ?? $trackers[0];
        $files = self::files($request);

        $data = new ReportData();
        $data->tracker = $tracker;
        $form = $this->form($project, $reporter, $trackers, $tracker, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $request->request->has('refresh')) {
            // Another category, other fields: what was typed for those of the first one is not kept.
            $data->fields = [];

            return $this->render('widget/report.html.twig', ['project' => $project, 'form' => $this->form($project, $reporter, $trackers, $tracker, $data), 'files' => $files, 'private' => $tracker->isPrivate()]);
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $issue = $this->commands->dispatch(new CreateIssue(
                    actorId: (int) $reporter->getId(),
                    projectId: (int) $project->getId(),
                    trackerId: (int) $tracker->getId(),
                    title: (string) $data->title,
                    description: (string) $data->description,
                    criticality: $data->criticality ?? Criticality::DEFAULT,
                    private: $data->private,
                    fields: array_filter($data->fields, static fn (mixed $value): bool => null !== $value && '' !== $value),
                    attachments: array_keys($files),
                    tagIds: array_map(static fn (Tag $tag): int => (int) $tag->getId(), $data->tags),
                    report: $data->includeReport ? self::decoded($data->technical) : null,
                ));
                \assert($issue instanceof Issue);

                return $this->redirectToRoute('app_widget_sent', self::route($project) + ['number' => $issue->getNumber()], Response::HTTP_SEE_OTHER);
            } catch (IssueException|TagException|AccessDeniedException) {
                // A tag deleted or a right taken back while the issue was being written is a refusal like another.
                $form->addError(new FormError($this->translator->trans('issue.create.refused')));
            }
        }

        return $this->render('widget/report.html.twig', ['project' => $project, 'form' => $form, 'files' => $files, 'private' => $tracker->isPrivate()], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * The report of the site as the issue would keep it: what the reporter reads before sending.
     */
    #[Route('/report/preview', name: 'app_widget_report_preview', methods: ['POST'])]
    public function preview(#[CurrentProject] Project $project, Request $request): Response
    {
        return $this->render('widget/_report.html.twig', ['report' => TechnicalReport::from(self::decoded($request->getContent()))]);
    }

    /**
     * A file or the screenshot, sent while the form is filled: it waits
     * for the issue, which claims it by the uuid answered here.
     */
    #[Route('/attachments', name: 'app_widget_attachment', methods: ['POST'])]
    public function attach(#[CurrentProject] Project $project, #[CurrentUser] User $reporter, Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => $this->translator->trans('widget.form.upload_failed')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $attachment = $this->commands->dispatch(new UploadAttachment((int) $reporter->getId(), (int) $project->getId(), $file));
            \assert($attachment instanceof Attachment);
        } catch (ValidationFailedException $e) {
            return $this->json(['error' => (string) $e->getViolations()->get(0)->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['uuid' => $attachment->getUuid()->toRfc4122(), 'name' => $attachment->getFilename()], Response::HTTP_CREATED);
    }

    #[Route('/report/sent/{number}', name: 'app_widget_sent', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function sent(#[CurrentProject] Project $project, int $number): Response
    {
        return $this->render('widget/sent.html.twig', ['project' => $project, 'number' => $number]);
    }

    /**
     * @param non-empty-list<Tracker> $trackers
     *
     * @return FormInterface<ReportData>
     */
    private function form(Project $project, User $reporter, array $trackers, Tracker $tracker, ReportData $data): FormInterface
    {
        $definition = $this->config->definition();

        return $this->createForm(ReportType::class, $data, [
            'action' => $this->generateUrl('app_widget_report', self::route($project)),
            'trackers' => $trackers,
            'workflow' => $definition->workflow($tracker->getWorkflowId()),
            'definition' => $definition,
            'internal' => $reporter->isInternal(),
            'criticality' => $this->access->isGranted($reporter, Permission::IssueSetCriticality, $project),
            'tags' => $this->access->isGranted($reporter, Permission::IssueSetTags, $project) ? $this->tags->findByProject($project) : [],
            // Issues of a private tracker are private whatever one chooses.
            'private' => !$tracker->isPrivate() && $this->access->isGranted($reporter, Permission::IssueSetPrivate, $project),
        ]);
    }

    /**
     * The files sent so far, as the form carries them: uuid => name.
     *
     * @return array<string, string>
     */
    private static function files(Request $request): array
    {
        $files = [];
        foreach ($request->request->all('attachments') as $uuid => $name) {
            if (\is_string($uuid) && \is_string($name) && 1 === preg_match('/^'.Requirement::UUID_V4.'$/i', $uuid)) {
                $files[$uuid] = mb_substr($name, 0, 255);
            }
        }

        return $files;
    }

    /**
     * @return array<mixed>|null
     */
    private static function decoded(?string $json): ?array
    {
        if (null === $json || '' === $json || \strlen($json) > 200_000) {
            return null;
        }
        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array{workspaceKey: string, projectKey: string}
     */
    private static function route(Project $project): array
    {
        return ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()];
    }
}
