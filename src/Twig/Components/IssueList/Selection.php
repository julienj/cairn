<?php

declare(strict_types=1);

namespace App\Twig\Components\IssueList;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Release;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\Tracker;
use App\Entity\User;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ChangeTracker;
use App\Message\Command\Issue\FollowIssue;
use App\Message\Command\Issue\MoveIssue;
use App\Message\Command\Issue\TagIssue;
use App\Message\Command\Issue\UnfollowIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\CommandBus;
use App\Message\Query\Issue\ListSelectionActions;
use App\Message\Query\Issue\SelectionActions;
use App\Message\QueryBus;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use App\Workflow\WorkflowProvider;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The selection page: the issues the viewer still sees among those
 * checked, and the changes to apply to all of them (ListSelectionActions
 * says which are offered). Changes add up, each left alone until chosen:
 * the step, the properties, a tag to add, following, a comment. Applying dispatches the
 * ordinary commands for each issue, each in its own transaction: the step
 * (MoveIssue, or ChangeTracker when a tracker was chosen and the issue is of another), one
 * UpdateIssue for the properties, TagIssue, the comment, the subscription. A refusal
 * stops the issue there and leaves the others done; the page then reports
 * what each issue got, and the refused ones can be taken again.
 */
#[AsLiveComponent('IssueList:Selection')]
final class Selection
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    /** @var list<int> */
    #[LiveProp]
    public array $issueIds = [];

    /** Where to go back: the list, as the page resolved it. */
    #[LiveProp]
    public string $back = '/';

    /** Whether the page is a project's: a list across projects changes no step. */
    #[LiveProp]
    public bool $inProject = false;

    /** The tracker every issue is taken to with the step; null leaves each in its own. */
    #[LiveProp]
    public ?int $trackerId = null;

    /** The step every issue is taken to: a place they all have, or one of the tracker chosen; null leaves it. */
    #[LiveProp]
    public ?string $placeId = null;

    /** Null leaves the assignee, UpdateIssue::NOBODY unassigns. */
    #[LiveProp]
    public ?int $assigneeId = null;

    /** The team every issue is given to, instead of a person: choosing one forgets the other. */
    #[LiveProp]
    public ?int $assigneeTeamId = null;

    #[LiveProp]
    public ?string $criticality = null;

    /** Null leaves the release, UpdateIssue::NOBODY takes the issues out of theirs. */
    #[LiveProp]
    public ?int $releaseId = null;

    /** The tag put on every issue, beside those each carries; null adds none. */
    #[LiveProp]
    public ?int $tagId = null;

    #[LiveProp]
    public ?bool $private = null;

    #[LiveProp]
    public ?bool $follow = null;

    /** The comment posted on every issue, when there is one. */
    #[LiveProp(writable: true)]
    public string $comment = '';

    #[LiveProp(writable: true)]
    public bool $commentPrivate = false;

    /**
     * What applying did, by issue key: the changes that went through and,
     * when one was refused, which and why.
     *
     * @var array<string, array{done: list<string>, refusal: ?string}>|null
     */
    #[LiveProp]
    public ?array $report = null;

    private ?SelectionActions $selection = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly TranslatorInterface $translator,
        private readonly WorkflowProvider $workflows,
    ) {
    }

    public function getSelection(): SelectionActions
    {
        return $this->selection ??= $this->queries->ask(new ListSelectionActions($this->viewer->get(), $this->issueIds));
    }

    /**
     * The trackers whose places the step menu lists: none outside the
     * page of a project.
     *
     * @return list<Tracker>
     */
    public function getTrackers(): array
    {
        return $this->inProject ? $this->getSelection()->moveTrackers : [];
    }

    /**
     * The trackers listed behind "Change category…": when the issues are
     * all of one tracker whose places the menu already offers, the others.
     *
     * @return list<Tracker>
     */
    public function getChangeTrackers(): array
    {
        $own = array_unique(array_map(static fn (Issue $issue): ?int => $issue->getTracker()->getId(), $this->getSelection()->issues));
        if ([] === $this->getPlaces() || 1 !== \count($own)) {
            return $this->getTrackers();
        }

        return array_values(array_filter($this->getTrackers(), static fn (Tracker $tracker): bool => !\in_array($tracker->getId(), $own, true)));
    }

    /**
     * The places every issue has in its own tracker, which the step menu
     * offers first: none outside the page of a project.
     *
     * @return list<PlaceDefinition>
     */
    public function getPlaces(): array
    {
        return $this->inProject ? $this->getSelection()->places : [];
    }

    /**
     * @return list<PlaceDefinition>
     */
    public function placesOf(Tracker $tracker): array
    {
        return $this->workflows->places($tracker->getWorkflowId());
    }

    public function getTargetTracker(): ?Tracker
    {
        return array_find($this->getTrackers(), fn (Tracker $tracker): bool => $tracker->getId() === $this->trackerId);
    }

    public function getTargetPlace(): ?PlaceDefinition
    {
        if (null === $this->placeId || (null !== $this->trackerId && null === $this->getTargetTracker())) {
            return null;
        }

        return $this->workflows->findPlace($this->placeId);
    }

    /**
     * Whether the issue goes to the step chosen: it changes tracker, or
     * stands elsewhere.
     */
    public function arrives(Issue $issue): bool
    {
        if (null === $this->getTargetPlace()) {
            return false;
        }

        return (null !== $this->trackerId && $issue->getTracker()->getId() !== $this->trackerId) || $issue->getPlace() !== $this->placeId;
    }

    public function getAssignee(): ?User
    {
        return array_find($this->getSelection()->assignees, fn (User $member): bool => $member->getId() === $this->assigneeId);
    }

    public function getAssigneeTeam(): ?Team
    {
        return array_find($this->getSelection()->assigneeTeams, fn (Team $team): bool => $team->getId() === $this->assigneeTeamId);
    }

    public function getRelease(): ?Release
    {
        return array_find($this->getSelection()->releases, fn (Release $release): bool => $release->getId() === $this->releaseId);
    }

    public function getTag(): ?Tag
    {
        return array_find($this->getSelection()->tags, fn (Tag $tag): bool => $tag->getId() === $this->tagId);
    }

    /**
     * Records the choice made in the menu of a property; no value leaves
     * the property alone. Only what the selection offers is kept.
     */
    #[LiveAction]
    public function change(#[LiveArg] string $property, #[LiveArg] ?string $value = null): void
    {
        $selection = $this->getSelection();
        // An id the selection offers, or UpdateIssue::NOBODY.
        $among = static fn (array $entities): bool => null === $value || (ctype_digit($value) && (UpdateIssue::NOBODY === (int) $value || array_any($entities, static fn (User|Release|Tag $entity): bool => $entity->getId() === (int) $value)));
        $offered = match ($property) {
            'assignee' => $selection->canAssign && $among($selection->assignees),
            // A team is given the issues, never "no team": nobody is a choice of the people.
            'assigneeTeam' => $selection->canAssign && null !== $value && ctype_digit($value) && array_any($selection->assigneeTeams, static fn (Team $team): bool => $team->getId() === (int) $value),
            'release' => [] !== $selection->releases && $among($selection->releases),
            // A tag is added, never taken off here: there is no "none" to choose.
            'tag' => (null === $value || 0 !== (int) $value) && $among($selection->tags),
            'criticality' => $selection->canSetCriticality && (null === $value || null !== Criticality::tryFrom($value)),
            'visibility' => $selection->canSetPrivate && \in_array($value, [null, 'public', 'private'], true),
            'follow' => \in_array($value, [null, 'follow', 'unfollow'], true),
            default => false,
        };
        if (!$offered) {
            return;
        }

        match ($property) {
            'assignee' => [$this->assigneeId, $this->assigneeTeamId] = [null === $value ? null : (int) $value, null],
            'assigneeTeam' => [$this->assigneeId, $this->assigneeTeamId] = [null, (int) $value],
            'release' => $this->releaseId = null === $value ? null : (int) $value,
            'tag' => $this->tagId = null === $value ? null : (int) $value,
            'criticality' => $this->criticality = $value,
            'visibility' => $this->private = null === $value ? null : 'private' === $value,
            'follow' => $this->follow = null === $value ? null : 'follow' === $value,
        };
    }

    /**
     * Records the step chosen: a place the issues have in common, each
     * staying in its tracker, or a place of a tracker the selection may
     * move to. Anything else leaves the step alone.
     */
    #[LiveAction]
    public function move(#[LiveArg] ?int $tracker = null, #[LiveArg] ?string $place = null): void
    {
        if (null === $tracker) {
            $this->trackerId = null;
            $this->placeId = array_any($this->getPlaces(), static fn (PlaceDefinition $offered): bool => $offered->id === $place) ? $place : null;

            return;
        }

        $target = array_find($this->getTrackers(), static fn (Tracker $offered): bool => $offered->getId() === $tracker);
        $known = null !== $target && null !== $place && $this->workflows->definition($target->getWorkflowId())->hasPlace($place);
        $this->trackerId = $known ? $tracker : null;
        $this->placeId = $known ? $place : null;
    }

    #[LiveAction]
    public function remove(#[LiveArg] int $id): void
    {
        $this->issueIds = array_values(array_diff($this->issueIds, [$id]));
        $this->selection = null;
    }

    /**
     * Applies the changes chosen to every issue of the selection, a
     * refusal stopping the issue it happens on.
     */
    #[LiveAction]
    public function apply(CommandBus $commands): void
    {
        // Applied already: a request sent twice must not comment twice.
        if (null !== $this->report) {
            return;
        }

        $report = [];
        foreach ($this->getSelection()->issues as $issue) {
            $done = [];
            $refusal = null;
            foreach ($this->changesOf($issue) as $label => $command) {
                $reason = $this->refusal($commands, $command);
                if (null !== $reason) {
                    $refusal = $this->translator->trans('issue.bulk.refused', ['%change%' => $label, '%reason%' => $reason]);
                    break;
                }
                $done[] = $label;
            }
            if ([] !== $done || null !== $refusal) {
                $report[$issue->getKey()] = ['done' => $done, 'refusal' => $refusal];
            }
        }

        if ([] === $report) {
            $this->error = $this->translator->trans('issue.bulk.nothing_chosen');

            return;
        }
        $this->report = $report;
        $this->selection = null;
    }

    /**
     * Back to the changes, with the refused issues only.
     */
    #[LiveAction]
    public function retry(): void
    {
        $refused = array_filter($this->report ?? [], static fn (array $result): bool => null !== $result['refusal']);
        $this->issueIds = array_values(array_map(
            static fn (Issue $issue): int => (int) $issue->getId(),
            array_filter($this->getSelection()->issues, static fn (Issue $issue): bool => isset($refused[$issue->getKey()])),
        ));
        $this->report = null;
        $this->selection = null;
    }

    /**
     * The commands the changes chosen mean for one issue, in the order
     * they are dispatched, each under the name the report gives it.
     *
     * @return iterable<string, object>
     */
    private function changesOf(Issue $issue): iterable
    {
        $actorId = (int) $this->viewer->get()->getId();
        $key = $issue->getKey();

        if (null !== $this->placeId) {
            $step = $this->translator->trans('issue.show.place');
            if (null !== $this->trackerId && $issue->getTracker()->getId() !== $this->trackerId) {
                yield $step => new ChangeTracker($actorId, $key, $this->trackerId, $this->placeId);
            } elseif ($issue->getPlace() !== $this->placeId) {
                yield $step => new MoveIssue($actorId, $key, $this->placeId);
            }
        }

        $properties = array_keys(array_filter([
            'issue.show.assignee' => null !== $this->assigneeId || null !== $this->assigneeTeamId,
            'issue.show.criticality' => null !== $this->criticality,
            'issue.show.release' => null !== $this->releaseId,
            'issue.show.visibility' => null !== $this->private,
        ]));
        if ([] !== $properties) {
            yield implode(', ', array_map($this->translator->trans(...), $properties)) => new UpdateIssue(
                $actorId,
                $key,
                assigneeId: $this->assigneeId,
                criticality: Criticality::tryFrom($this->criticality ?? ''),
                private: $this->private,
                releaseId: $this->releaseId,
                assigneeTeamId: $this->assigneeTeamId,
            );
        }

        if (null !== $this->tagId) {
            yield $this->translator->trans('issue.bulk.add_tag') => new TagIssue($actorId, $key, $this->tagId);
        }

        if ('' !== trim($this->comment)) {
            yield $this->translator->trans('issue.bulk.comment_label') => new AddComment($actorId, $key, $this->comment, $this->commentPrivate && $this->getSelection()->canCommentPrivately);
        }

        // Last: commenting subscribes its author again.
        if (null !== $this->follow) {
            yield $this->translator->trans('issue.bulk.follow') => $this->follow ? new FollowIssue($actorId, $key) : new UnfollowIssue($actorId, $key);
        }
    }
}
