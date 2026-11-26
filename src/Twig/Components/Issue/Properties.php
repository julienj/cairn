<?php

declare(strict_types=1);

namespace App\Twig\Components\Issue;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Release;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Command\Issue\ChangeTracker;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\TagIssue;
use App\Message\Command\Issue\UntagIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListProjectTeams;
use App\Message\Query\Project\ListTrackers;
use App\Message\QueryBus;
use App\Repository\IssueTagRepository;
use App\Repository\ReleaseRepository;
use App\Repository\TagRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\PlaceMove;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Workflow\WorkflowProvider;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The properties of an issue in the side panel of its page: step,
 * criticality, tags, assignee, release and visibility read as values and,
 * for whoever may change them, open a menu of the choices; the release
 * shows once the project has releases, the tags once it has tags. The
 * menu of the tags ticks several and stays open.
 * The step is a menu for whoever moves freely
 * (`AccessControl::canMoveFreely()`): the other places of the workflow
 * and, last, the other trackers of the project, each with the places of
 * its workflow; the places are drawn as the path of the workflow, those
 * beside it hanging from the place they stand beside (`getTrail()`). A
 * role held to the workflow reads the step and acts from the response
 * box, where an action is explained. Next to the tracker, a link opens
 * the diagram of its workflow, for everyone.
 * A change is its own command; the other components of the page are told
 * (`issue:changed`), and this one follows their changes.
 */
#[AsLiveComponent('Issue:Properties')]
final class Properties
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use GuardsIssue;

    #[LiveProp]
    public Issue $issue;

    /** @var list<PlaceMove>|null read once per render, forgotten when the issue moves */
    private ?array $moves = null;

    /** @var list<Tracker>|null read once per request: the trackers of the project do not change with the issue */
    private ?array $otherTrackers = null;

    public function __construct(
        private readonly AccessControl $access,
        private readonly Viewer $viewer,
        private readonly QueryBus $queries,
        private readonly ReleaseRepository $releases,
        private readonly TagRepository $tags,
        private readonly IssueTagRepository $issueTags,
        private readonly WorkflowProvider $workflows,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Whether the step is a menu: when it leads somewhere, which it only
     * does for whoever moves freely.
     */
    public function canChangePlace(): bool
    {
        return [] !== $this->getMoves() || [] !== $this->getOtherTrackers();
    }

    /**
     * The places the menu offers: every other one of the workflow, for
     * whoever moves freely.
     *
     * @return list<PlaceMove>
     */
    public function getMoves(): array
    {
        $viewer = $this->viewer->get();

        return $this->moves ??= $this->access->canMoveFreely($viewer, $this->issue->getProject()) ? $this->access->movesFor($viewer, $this->issue) : [];
    }

    /**
     * The trackers the issue may change to, for whoever moves freely: the
     * other active ones of the project the viewer sees.
     *
     * @return list<Tracker>
     */
    public function getOtherTrackers(): array
    {
        if (null !== $this->otherTrackers) {
            return $this->otherTrackers;
        }
        if (!$this->access->canMoveFreely($this->viewer->get(), $this->issue->getProject())) {
            return $this->otherTrackers = [];
        }
        $current = $this->issue->getTracker()->getId();

        return $this->otherTrackers = array_values(array_filter(
            $this->queries->ask(new ListTrackers($this->viewer->get(), $this->issue->getProject())),
            static fn (Tracker $tracker): bool => $tracker->getId() !== $current,
        ));
    }

    /**
     * The places of a tracker's workflow, in its order; of the issue's own
     * tracker by default.
     *
     * @return list<PlaceDefinition>
     */
    public function placesOf(?Tracker $tracker = null): array
    {
        return $this->workflows->places(($tracker ?? $this->issue->getTracker())->getWorkflowId());
    }

    /**
     * The places of the issue's workflow as its diagram arranges them: the
     * path in its order, each place followed by those that stand beside it.
     *
     * @return list<array{place: PlaceDefinition, aside: bool}>
     */
    public function getTrail(): array
    {
        $workflow = $this->workflows->definition($this->issue->getTracker()->getWorkflowId());
        $beside = [];
        foreach ($workflow->places as $place) {
            if (isset($workflow->aside[$place])) {
                $beside[$workflow->aside[$place]][] = $place;
            }
        }

        $trail = [];
        foreach ($workflow->path() as $place) {
            $trail[] = ['place' => $this->workflows->place($place), 'aside' => false];
            foreach ($beside[$place] ?? [] as $aside) {
                $trail[] = ['place' => $this->workflows->place($aside), 'aside' => true];
            }
            unset($beside[$place]);
        }
        // The definition judges nothing: a place beside one that is not on the path still shows.
        foreach ($beside as $asides) {
            foreach ($asides as $aside) {
                $trail[] = ['place' => $this->workflows->place($aside), 'aside' => true];
            }
        }

        return $trail;
    }

    /**
     * Redraws with the issue as another component left it.
     */
    #[LiveListener('issue:changed')]
    public function refresh(): void
    {
    }

    public function canAssign(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueAssign, $this->issue->getProject());
    }

    public function canSetCriticality(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueSetCriticality, $this->issue->getProject());
    }

    public function canSetRelease(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueSetRelease, $this->issue->getProject());
    }

    public function hasReleases(): bool
    {
        return $this->releases->hasReleases($this->issue->getProject());
    }

    /**
     * The releases the issue can go to: the open ones, and the one it is
     * in even when delivered or abandoned, so that the choice shows it.
     *
     * @return list<Release>
     */
    public function getReleaseChoices(): array
    {
        $choices = $this->releases->findByProject($this->issue->getProject(), openOnly: true);
        $current = $this->issue->getRelease();
        if (null !== $current && !$current->isOpen()) {
            $choices[] = $current;
        }

        return $choices;
    }

    public function canSetTags(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueSetTags, $this->issue->getProject());
    }

    /**
     * The tags of the project, by name: none, and the issue shows no row
     * for them.
     *
     * @return list<Tag>
     */
    public function getTagChoices(): array
    {
        return $this->tags->findByProject($this->issue->getProject());
    }

    /**
     * @return list<Tag>
     */
    public function getTags(): array
    {
        return $this->issueTags->tagsOf($this->issue);
    }

    /**
     * Puts the tag on the issue, or takes it off when it carries it.
     */
    #[LiveAction]
    public function toggleTag(CommandBus $commands, #[LiveArg] int $tag): void
    {
        $actorId = (int) $this->viewer->get()->getId();
        $key = $this->issue->getKey();
        $carried = array_any($this->getTags(), static fn (Tag $carried): bool => $carried->getId() === $tag);
        if ($this->tryCommand($commands, $carried ? new UntagIssue($actorId, $key, $tag) : new TagIssue($actorId, $key, $tag))) {
            $this->announce($this->translator->trans('issue.action.done', ['%key%' => $key]));
        }
    }

    /**
     * An issue of a private tracker has no visibility to choose.
     */
    public function canSetVisibility(): bool
    {
        return !$this->issue->getTracker()->isPrivate()
            && $this->access->isGranted($this->viewer->get(), Permission::IssueSetPrivate, $this->issue->getProject());
    }

    /**
     * @return list<User>
     */
    public function getMembers(): array
    {
        return $this->queries->ask(new ListProjectMembers($this->issue->getProject()));
    }

    /**
     * The teams the issue may be given to: those that hold a role on its
     * project.
     *
     * @return list<Team>
     */
    public function getTeams(): array
    {
        return $this->queries->ask(new ListProjectTeams($this->issue->getProject()));
    }

    /**
     * Applies the choice made in the menu of a property: an id or nothing
     * for the assignee and the release, the id of a team, a level,
     * `public` or `private`.
     */
    #[LiveAction]
    public function change(CommandBus $commands, #[LiveArg] string $property, #[LiveArg] string $value): void
    {
        $actorId = (int) $this->viewer->get()->getId();
        $key = $this->issue->getKey();
        $criticality = Criticality::tryFrom($value);
        $command = match ($property) {
            'assignee' => new UpdateIssue($actorId, $key, assigneeId: self::idOrNobody($value)),
            'assigneeTeam' => ctype_digit($value) && (int) $value > 0 ? new UpdateIssue($actorId, $key, assigneeTeamId: (int) $value) : null,
            'criticality' => null === $criticality ? null : new UpdateIssue($actorId, $key, criticality: $criticality),
            'release' => new UpdateIssue($actorId, $key, releaseId: self::idOrNobody($value)),
            'visibility' => new UpdateIssue($actorId, $key, private: 'private' === $value),
            default => null,
        };
        if (null === $command) {
            return;
        }

        if (!$this->tryCommand($commands, $command)) {
            return;
        }

        $this->announce($this->translator->trans('issue.action.done', ['%key%' => $key]));
    }

    /**
     * Takes the issue to a place of its workflow, or to another tracker on
     * a place of its workflow.
     */
    #[LiveAction]
    public function move(CommandBus $commands, #[LiveArg] string $place, #[LiveArg] ?int $tracker = null): void
    {
        // The browser's choice is checked again against what the menu offers.
        $offered = null === $tracker
            ? array_any($this->getMoves(), static fn (PlaceMove $move): bool => $move->place->id === $place)
            : array_any($this->getOtherTrackers(), static fn (Tracker $other): bool => $other->getId() === $tracker);
        if (!$offered) {
            $this->error = $this->translator->trans('issue.action.refused');

            return;
        }

        $actorId = (int) $this->viewer->get()->getId();
        $command = null === $tracker ? new MoveIssue($actorId, $this->issue->getKey(), $place) : new ChangeTracker($actorId, $this->issue->getKey(), $tracker, $place);
        if ($this->tryCommand($commands, $command)) {
            $this->moves = null;
            $this->otherTrackers = null;
            $this->announce($this->translator->trans('issue.move.done', ['%key%' => $this->issue->getKey(), '%place%' => $this->workflows->placeOf($this->issue)->label]));
        }
    }

    private static function idOrNobody(string $value): int
    {
        return '' === $value ? UpdateIssue::NOBODY : (int) $value;
    }
}
