<?php

declare(strict_types=1);

namespace App\Twig\Components\IssueList;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Form\Issue\IssueListFilters;
use App\Message\Query\Issue\IssueSort;

/**
 * What the route of an issue list fixes, and the browser cannot change:
 * the issues it lists (a project, a tracker, the viewer as assignee or as
 * author), the filters it offers, and its address without any parameter,
 * which names the list (the view a user keeps of it is filed under it).
 */
final readonly class ListScope
{
    public const array DIMENSIONS = ['place', 'project', 'tracker', 'criticality', 'assignee', 'release', 'tag'];

    /**
     * @param string       $path         the address of the list, without its query string
     * @param list<string> $dimensions   the filters offered, among self::DIMENSIONS
     * @param ?Tracker     $tracker      the tracker the list is scoped to; its filter is then not offered
     * @param bool         $showProject  adds the project column, for the lists across projects
     * @param ?int         $teamMemberId the issues given to a team this user is part of
     */
    public function __construct(
        public string $path,
        public array $dimensions,
        public ?Project $project = null,
        public ?Tracker $tracker = null,
        public ?int $assigneeId = null,
        public ?int $authorId = null,
        public bool $showProject = false,
        public ?int $teamMemberId = null,
    ) {
    }

    /**
     * The list of one tracker, the only one that also shows as a kanban:
     * the tracker is the scope, so its filter is not offered. The columns
     * of the kanban, which build no address, leave the path out.
     */
    public static function ofTracker(Tracker $tracker, string $path = ''): self
    {
        return new self($path, ['place', 'criticality', 'assignee', 'release', 'tag'], $tracker->getProject(), $tracker);
    }

    public function offers(string $dimension): bool
    {
        return \in_array($dimension, self::DIMENSIONS, true) && \in_array($dimension, $this->dimensions, true)
            && ('tracker' !== $dimension || null === $this->tracker);
    }

    /**
     * A kanban has one workflow: only the list of a tracker shows as one.
     */
    public function hasBoard(): bool
    {
        return null !== $this->tracker;
    }

    /**
     * The view the list shows: the kanban when asked for and the list has one.
     */
    public function view(IssueListFilters $filters): string
    {
        return $this->hasBoard() ? $filters->view : IssueListFilters::VIEW_LIST;
    }

    /**
     * The column the list is sorted by, when it shows it: no table has a
     * column for the rank, which orders the kanban.
     */
    public function sort(IssueListFilters $filters): ?IssueSort
    {
        return match (true) {
            IssueSort::Rank === $filters->sort, IssueSort::Project === $filters->sort && !$this->showProject => null,
            default => $filters->sort,
        };
    }

    /**
     * The address of the list arranged this way.
     */
    public function url(IssueListFilters $filters): string
    {
        $query = $filters->queryString();

        return $this->path.('' === $query ? '' : '?'.$query);
    }
}
