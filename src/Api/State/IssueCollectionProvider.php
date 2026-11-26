<?php

declare(strict_types=1);

namespace App\Api\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Api\Mapper\IssueMapper;
use App\Api\Resource\IssueResource;
use App\Config\ConfigRegistry;
use App\Entity\Enum\Criticality;
use App\Message\Query\Issue\IssueSort;
use App\Message\Query\Issue\SearchIssues;
use App\Message\QueryBus;
use App\Repository\Pagination as Page;
use App\Security\Viewer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * GET /issues: the filters of the query string become a SearchIssues, so
 * the list is the one the application shows for the same filters. A
 * filter the API does not understand is a 400, never ignored: a program
 * must not take a wider list for the one it asked.
 *
 * @implements ProviderInterface<IssueResource>
 */
final class IssueCollectionProvider implements ProviderInterface
{
    private const string ME = 'me';
    private const string NONE = 'none';

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly Lookup $lookup,
        private readonly IssueMapper $issues,
        private readonly Pagination $pagination,
        private readonly ConfigRegistry $config,
    ) {
    }

    /**
     * @return TraversablePaginator<IssueResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);
        $page = $this->search($request->query->all(), $this->pagination->getPage($context), $this->pagination->getLimit($operation, $context));

        return new TraversablePaginator(new \ArrayIterator($page->items), $page->page, $page->perPage, $page->total);
    }

    /**
     * The page of the list the filters ask for, as a query string names
     * them: the one place that reads them, for the MCP server too.
     *
     * @param array<array-key, mixed> $filters
     *
     * @return Page<IssueResource>
     */
    public function search(array $filters, int $page, int $perPage): Page
    {
        $viewer = $this->viewer->get();

        $projectId = null;
        if (isset($filters['project'])) {
            $project = $this->lookup->findProject(self::text($filters, 'project')) ?? throw new BadRequestHttpException('project: no such project.');
            $projectId = (int) $project->getId();
        }

        $sort = isset($filters['sort']) ? (self::sort(self::text($filters, 'sort')) ?? throw new BadRequestHttpException('sort: unknown column.')) : null;
        $order = match (isset($filters['order']) ? self::text($filters, 'order') : 'asc') {
            'asc' => \SortDirection::Ascending,
            'desc' => \SortDirection::Descending,
            default => throw new BadRequestHttpException('order: asc or desc.'),
        };

        $found = $this->queries->ask(new SearchIssues(
            viewer: $viewer,
            projectId: $projectId,
            authorId: isset($filters['author']) ? self::id(self::text($filters, 'author'), 'author', [self::ME => (int) $viewer->getId()]) : null,
            place: array_map(fn (string $value): string => $this->config->definition()->hasPlace($value) ? $value : throw new BadRequestHttpException('place: unknown step.'), self::list($filters, 'place')),
            tracker: array_map(static fn (string $value): int => self::id($value, 'tracker'), self::list($filters, 'tracker')),
            assignee: array_map(static fn (string $value): int => self::id($value, 'assignee', [self::ME => (int) $viewer->getId(), self::NONE => 0]), self::list($filters, 'assignee')),
            criticality: array_map(static fn (string $value): Criticality => Criticality::tryFrom($value) ?? throw new BadRequestHttpException('criticality: unknown level.'), self::list($filters, 'criticality')),
            release: array_map(static fn (string $value): int => self::id($value, 'release', [self::NONE => 0]), self::list($filters, 'release')),
            q: isset($filters['q']) ? self::text($filters, 'q') : null,
            closed: isset($filters['closed']) && self::flag($filters['closed']),
            page: $page,
            sort: $sort,
            order: $order,
            perPage: $perPage,
            team: array_map(static fn (string $value): int => self::id($value, 'assigneeTeam'), self::list($filters, 'assigneeTeam')),
        ));

        return new Page($this->issues->summaries($found->items), $found->total, $found->page, $found->perPage);
    }

    /**
     * @param array<array-key, mixed> $filters
     */
    private static function text(array $filters, string $name): string
    {
        return \is_string($filters[$name]) ? $filters[$name] : throw new BadRequestHttpException($name.': a value.');
    }

    /**
     * A flag as a query string gives it (`closed`, `closed=1`, `closed=true`) or as a boolean.
     */
    private static function flag(mixed $value): bool
    {
        if (!\is_scalar($value)) {
            throw new BadRequestHttpException('closed: a boolean.');
        }

        return '' === $value || filter_var($value, \FILTER_VALIDATE_BOOL);
    }

    /**
     * The values of a filter given once (`tracker=3`) or several times (`tracker[]=3&tracker[]=4`).
     *
     * @param array<array-key, mixed> $filters
     *
     * @return list<string>
     */
    private static function list(array $filters, string $name): array
    {
        $values = $filters[$name] ?? [];
        $values = \is_array($values) ? array_values($values) : [$values];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new BadRequestHttpException($name.': a value or a list of values.');
            }
        }

        return $values;
    }

    /**
     * A column a program may sort by: the rank orders the kanban only.
     */
    private static function sort(string $value): ?IssueSort
    {
        $sort = IssueSort::tryFrom($value);

        return IssueSort::Rank === $sort ? null : $sort;
    }

    /**
     * @param array<string, int> $words the words the filter takes in place of an id
     */
    private static function id(string $value, string $name, array $words = []): int
    {
        if (isset($words[$value])) {
            return $words[$value];
        }
        if (!ctype_digit($value) || \strlen($value) > 9 || 0 === (int) $value) {
            throw new BadRequestHttpException($name.': an id'.([] === $words ? '' : ', '.implode(' or ', array_keys($words))).'.');
        }

        return (int) $value;
    }
}
