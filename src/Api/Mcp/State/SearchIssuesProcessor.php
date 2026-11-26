<?php

declare(strict_types=1);

namespace App\Api\Mcp\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Api\Mcp\IssueList;
use App\Api\Mcp\SearchIssuesTool;
use App\Api\State\IssueCollectionProvider;

/**
 * @implements ProcessorInterface<SearchIssuesTool, IssueList>
 */
final class SearchIssuesProcessor implements ProcessorInterface
{
    private const int PER_PAGE = 50;

    public function __construct(
        private readonly IssueCollectionProvider $issues,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IssueList
    {
        \assert($data instanceof SearchIssuesTool);

        $filters = array_filter([
            'project' => $data->project,
            'q' => $data->q,
            'assignee' => $data->assignee,
            'assigneeTeam' => array_map(strval(...), $data->assigneeTeam),
            'author' => $data->author,
            'tracker' => array_map(strval(...), $data->tracker),
            'place' => $data->place,
            'criticality' => $data->criticality,
            'release' => $data->release,
            'closed' => $data->closed,
            'sort' => $data->sort,
            'order' => $data->order,
        ], static fn (mixed $value): bool => !\in_array($value, [null, '', [], false], true));
        $page = $this->issues->search($filters, $data->page, self::PER_PAGE);

        return new IssueList($page->total, $page->page, $page->pageCount(), $page->items);
    }
}
