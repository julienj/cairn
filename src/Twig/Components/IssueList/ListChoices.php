<?php

declare(strict_types=1);

namespace App\Twig\Components\IssueList;

use App\Config\ConfigRegistry;
use App\Config\Definition\PlaceDefinition;
use App\Entity\Issue;
use App\Entity\Release;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\Tracker;
use App\Entity\User;
use App\Form\Issue\IssueListFilters;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListProjectTeams;
use App\Message\Query\Project\ListTrackers;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Project\ProjectSummary;
use App\Message\QueryBus;
use App\Repository\IssueTagRepository;
use App\Repository\ReleaseRepository;
use App\Repository\TagRepository;
use App\Security\Viewer;

/**
 * What an issue list offers its viewer to filter and group by, and the
 * search it runs: the one place that turns a scope and the filters of an
 * address into a SearchIssues, for the table and for the columns of the
 * kanban alike, keeping of the filters what the list offers. Users see
 * places, never statuses: a place is the same in every workflow that uses
 * it, so a list mixing workflows offers it once. Each choice is read once
 * for a scope, whichever component asks first.
 */
final class ListChoices
{
    /** @var \WeakMap<ListScope, list<Tracker>> */
    private \WeakMap $trackers;

    /** @var \WeakMap<ListScope, list<Release>> */
    private \WeakMap $releases;

    /** @var \WeakMap<ListScope, list<Tag>> */
    private \WeakMap $tags;

    /** @var \WeakMap<ListScope, list<PlaceDefinition>> */
    private \WeakMap $places;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly ReleaseRepository $releaseRepository,
        private readonly TagRepository $tagRepository,
        private readonly IssueTagRepository $issueTags,
        private readonly ConfigRegistry $config,
    ) {
        $this->trackers = new \WeakMap();
        $this->releases = new \WeakMap();
        $this->tags = new \WeakMap();
        $this->places = new \WeakMap();
    }

    /**
     * The search of the list as it is arranged.
     */
    public function search(ListScope $scope, IssueListFilters $filters): SearchIssues
    {
        return new SearchIssues(
            viewer: $this->viewer->get(),
            projectId: $scope->project?->getId(),
            assigneeId: $scope->assigneeId,
            teamMemberId: $scope->teamMemberId,
            authorId: $scope->authorId,
            place: $scope->offers('place') ? $this->knownPlaces($scope, $filters) : [],
            project: $scope->offers('project') ? $filters->projects : [],
            tracker: null !== $scope->tracker ? [(int) $scope->tracker->getId()] : ($scope->offers('tracker') ? $filters->trackers : []),
            assignee: $scope->offers('assignee') ? $filters->assignees : [],
            team: $scope->offers('assignee') ? $filters->teams : [],
            criticality: $scope->offers('criticality') ? $filters->criticality : [],
            release: [] !== $filters->releases && $this->offersReleases($scope) ? $filters->releases : [],
            tag: $this->knownTags($scope, $filters),
            q: $filters->q,
            closed: $filters->closed,
            page: $filters->page,
            sort: $scope->sort($filters),
            order: $filters->order,
            group: $this->grouping($scope, $filters),
        );
    }

    /**
     * The search of one column of the kanban of a tracker: the issues at
     * that place, in the default order, in one page of `$perPage`. The
     * place filter, the sort and the grouping belong to the list.
     */
    public function searchColumn(ListScope $scope, IssueListFilters $filters, string $placeId, int $perPage): SearchIssues
    {
        $tracker = $scope->tracker ?? throw new \LogicException('Only the list of a tracker has a kanban.');

        return new SearchIssues(
            viewer: $this->viewer->get(),
            projectId: $tracker->getProject()->getId(),
            assigneeId: $scope->assigneeId,
            teamMemberId: $scope->teamMemberId,
            authorId: $scope->authorId,
            place: [$placeId],
            tracker: [(int) $tracker->getId()],
            assignee: $scope->offers('assignee') ? $filters->assignees : [],
            team: $scope->offers('assignee') ? $filters->teams : [],
            criticality: $scope->offers('criticality') ? $filters->criticality : [],
            release: [] !== $filters->releases && $this->offersReleases($scope) ? $filters->releases : [],
            tag: $this->knownTags($scope, $filters),
            q: $filters->q,
            sort: IssueSort::Rank,
            order: \SortDirection::Descending,
            perPage: $perPage,
        );
    }

    /**
     * The places offered by the filter, in the order of the instance: those
     * the workflows of the list use, the tracker's, the project's
     * trackers', every workflow's across projects.
     *
     * @return list<PlaceDefinition>
     */
    public function places(ListScope $scope): array
    {
        $definition = $this->config->definition();

        return $this->places[$scope] ??= $definition->placesIn(match (true) {
            null !== $scope->tracker => [$scope->tracker->getWorkflowId()],
            null !== $scope->project => array_map(static fn (Tracker $tracker): string => $tracker->getWorkflowId(), $this->trackers($scope)),
            default => array_keys($definition->workflows),
        });
    }

    /**
     * The trackers of the project the viewer sees, archived ones included.
     *
     * @return list<Tracker>
     */
    public function trackers(ListScope $scope): array
    {
        return $this->trackers[$scope] ??= null === $scope->project ? [] : $this->queries->ask(new ListTrackers($this->viewer->get(), $scope->project, archived: true));
    }

    /**
     * The releases of the project, open ones first.
     *
     * @return list<Release>
     */
    public function releases(ListScope $scope): array
    {
        return $this->releases[$scope] ??= null === $scope->project ? [] : $this->releaseRepository->findByProject($scope->project);
    }

    /**
     * The release filter is offered on the lists of a project that has
     * releases, when the page offers it.
     */
    public function offersReleases(ListScope $scope): bool
    {
        return $scope->offers('release') && [] !== $this->releases($scope);
    }

    /**
     * The tags of the project, by name.
     *
     * @return list<Tag>
     */
    public function tags(ListScope $scope): array
    {
        return $this->tags[$scope] ??= null === $scope->project ? [] : $this->tagRepository->findByProject($scope->project);
    }

    /**
     * The tag filter is offered on the lists of a project that has tags,
     * when the page offers it.
     */
    public function offersTags(ListScope $scope): bool
    {
        return $scope->offers('tag') && [] !== $this->tags($scope);
    }

    /**
     * The tags the issues of a page carry, which their rows and cards
     * show, across projects too: one read for the page.
     *
     * @param iterable<Issue> $issues
     *
     * @return array<int, list<Tag>> issue id => its tags by name
     */
    public function tagsOf(iterable $issues): array
    {
        return $this->issueTags->tagsByIssue($issues);
    }

    /**
     * @return list<User>
     */
    public function members(ListScope $scope): array
    {
        return null === $scope->project ? [] : $this->queries->ask(new ListProjectMembers($scope->project));
    }

    /**
     * The teams the assignee filter offers with the people: those that
     * hold a role on the project.
     *
     * @return list<Team>
     */
    public function teams(ListScope $scope): array
    {
        return null === $scope->project ? [] : $this->queries->ask(new ListProjectTeams($scope->project));
    }

    /**
     * @return list<ProjectSummary>
     */
    public function projects(): array
    {
        return $this->queries->ask(new ListVisibleProjects($this->viewer->get()));
    }

    /**
     * What the list can group by: the project only across projects, the
     * assignee unless the list is the viewer's own, the release in a
     * project that has some.
     *
     * @return list<IssueGrouping>
     */
    public function groupings(ListScope $scope): array
    {
        return array_values(array_filter(IssueGrouping::cases(), fn (IssueGrouping $grouping): bool => match ($grouping) {
            IssueGrouping::Project => $scope->showProject,
            IssueGrouping::Assignee => null === $scope->assigneeId,
            IssueGrouping::Release => [] !== $this->releases($scope),
            default => true,
        }));
    }

    /**
     * The grouping of the list, when it offers the one asked for.
     */
    public function grouping(ListScope $scope, IssueListFilters $filters): ?IssueGrouping
    {
        return \in_array($filters->group, $this->groupings($scope), true) ? $filters->group : null;
    }

    /**
     * The chosen tags the project still has, and "none": a tag deleted
     * since the list was arranged filters nothing.
     *
     * @return list<int>
     */
    private function knownTags(ListScope $scope, IssueListFilters $filters): array
    {
        if ([] === $filters->tags || !$this->offersTags($scope)) {
            return [];
        }
        $known = [0, ...array_map(static fn (Tag $tag): int => (int) $tag->getId(), $this->tags($scope))];

        return array_values(array_intersect($filters->tags, $known));
    }

    /**
     * The chosen places that belong to the workflows of the list.
     *
     * @return list<string>
     */
    private function knownPlaces(ListScope $scope, IssueListFilters $filters): array
    {
        return array_values(array_intersect($filters->places, array_map(static fn (PlaceDefinition $place): string => $place->id, $this->places($scope))));
    }
}
