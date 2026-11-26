<?php

declare(strict_types=1);

namespace App\Form\Issue;

use App\Entity\Enum\Criticality;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\IssueSort;

/**
 * How an issue list is arranged, as its address says it: the filter
 * values, the sorted column, the grouping, the page and the view (list or
 * kanban). The list and the kanban read the same one, so a filter holds
 * across both. Built from a query string it keeps what it understands: a
 * value it does not know is dropped, never an error. What depends on the
 * list (the places of its workflows, the groupings it offers) is checked
 * by IssueList\ListChoices.
 */
final readonly class IssueListFilters
{
    public const string VIEW_LIST = 'list';
    public const string VIEW_BOARD = 'board';

    /**
     * @param list<string>      $places      place ids
     * @param list<int>         $projects
     * @param list<int>         $trackers
     * @param list<int>         $assignees   user ids, 0 for unassigned
     * @param list<int>         $teams       team ids, read with the assignees: an issue given to one of them, person or team
     * @param list<int>         $releases    release ids, 0 for the issues in no release
     * @param list<int>         $tags        tag ids, 0 for the issues without any tag
     * @param list<Criticality> $criticality
     * @param bool              $closed      list closed issues too
     * @param ?IssueSort        $sort        null for the default order
     * @param ?IssueGrouping    $group       null for none
     */
    private function __construct(
        public array $places = [],
        public array $projects = [],
        public array $trackers = [],
        public array $assignees = [],
        public array $releases = [],
        public array $tags = [],
        public array $criticality = [],
        public string $q = '',
        public bool $closed = false,
        public int $page = 1,
        public ?IssueSort $sort = null,
        public \SortDirection $order = \SortDirection::Ascending,
        public ?IssueGrouping $group = null,
        public string $view = self::VIEW_LIST,
        public array $teams = [],
    ) {
    }

    /**
     * @param array<mixed> $query the parameters of a query string, as PHP parsed them
     */
    public static function fromQuery(array $query): self
    {
        $sort = self::enum(IssueSort::class, $query['sort'] ?? null);

        return new self(
            places: array_values(array_unique(array_filter(self::strings($query['places'] ?? null), static fn (string $place): bool => 1 === preg_match('/^[a-z][a-z0-9_]*$/', $place)))),
            projects: QueryIds::of($query['projects'] ?? null),
            trackers: QueryIds::of($query['trackers'] ?? null),
            assignees: QueryIds::of($query['assignees'] ?? null, withZero: true),
            releases: QueryIds::of($query['releases'] ?? null, withZero: true),
            tags: QueryIds::of($query['tags'] ?? null, withZero: true),
            criticality: array_values(array_filter(array_map(Criticality::tryFrom(...), array_unique(self::strings($query['criticality'] ?? null))))),
            q: \is_string($query['q'] ?? null) ? trim($query['q']) : '',
            closed: \in_array($query['closed'] ?? null, ['1', 'on', 'true', 1, true], true),
            page: max(1, \is_scalar($query['page'] ?? null) ? (int) $query['page'] : 1),
            sort: $sort,
            order: null !== $sort && 'desc' === ($query['order'] ?? null) ? \SortDirection::Descending : \SortDirection::Ascending,
            group: self::enum(IssueGrouping::class, $query['group'] ?? null),
            view: self::VIEW_BOARD === ($query['view'] ?? null) ? self::VIEW_BOARD : self::VIEW_LIST,
            teams: QueryIds::of($query['teams'] ?? null),
        );
    }

    /**
     * The same arrangement with some parameters changed, for the links of a
     * list: another page, another sorted column.
     *
     * @param array<string, mixed> $changes parameters of the query string; null removes one
     */
    public function with(array $changes): self
    {
        return self::fromQuery([...$this->toQuery(), ...$changes]);
    }

    /**
     * The parameters of the address that says this arrangement, what a list
     * shows anyway left out: the first page, the list view, no sort.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        return array_filter([
            'view' => self::VIEW_LIST === $this->view ? null : $this->view,
            'places' => $this->places,
            'projects' => $this->projects,
            'trackers' => $this->trackers,
            'criticality' => $this->criticalityValues(),
            'assignees' => $this->assignees,
            'teams' => $this->teams,
            'releases' => $this->releases,
            'tags' => $this->tags,
            'q' => $this->q,
            'closed' => $this->closed ? 1 : null,
            'group' => $this->group?->value,
            'sort' => $this->sort?->value,
            'order' => \SortDirection::Descending === $this->order ? 'desc' : null,
            'page' => 1 === $this->page ? null : $this->page,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value && [] !== $value);
    }

    /**
     * The query string of that address, lists written `name[]=` as a form
     * sends them.
     */
    public function queryString(): string
    {
        return (string) preg_replace('/%5B\d+%5D=/', '%5B%5D=', http_build_query($this->toQuery()));
    }

    /**
     * What a list remembers for its next visit: the filters, the sort, the
     * grouping and the view, not the page nor the words searched.
     */
    public function remembered(): self
    {
        return $this->with(['page' => null, 'q' => null]);
    }

    /**
     * Whether a filter narrows the list, which "clear" then offers to undo;
     * the sort, the grouping, the view and showing the finished issues
     * arrange it without narrowing it.
     */
    public function hasFilters(): bool
    {
        return [] !== $this->places || [] !== $this->projects || [] !== $this->trackers || [] !== $this->assignees || [] !== $this->teams || [] !== $this->releases || [] !== $this->tags
            || [] !== $this->criticality || '' !== $this->q;
    }

    /**
     * @return list<string>
     */
    public function criticalityValues(): array
    {
        return array_map(static fn (Criticality $criticality): string => $criticality->value, $this->criticality);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private static function enum(string $enum, mixed $value): ?\BackedEnum
    {
        return \is_string($value) ? $enum::tryFrom($value) : null;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        return \is_array($values) ? array_values(array_filter($values, \is_string(...))) : [];
    }
}
