<?php

declare(strict_types=1);

namespace App\Api\Mcp;

use ApiPlatform\Metadata\McpTool;
use App\Api\Mcp\State\SearchIssuesProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The filters of GET /api/issues, as arguments.
 */
#[McpTool(
    name: 'search_issues',
    description: 'Lists the issues the user may see, 50 a page, filtered and sorted like the lists of the application. Without `closed`, the finished issues are left out. The description of an issue is read with get_issue.',
    structuredContent: false,
    outputFormats: ['json' => ['application/json']],
    security: "is_granted('api.read')",
    validate: true,
    output: IssueList::class,
    processor: SearchIssuesProcessor::class,
)]
final class SearchIssuesTool
{
    /** Key of a project, `MD-SPT`. */
    public ?string $project = null;

    /** Words of the title or the description, or a key. */
    public ?string $q = null;

    /**
     * Ids of users, `me`, or `none` for the unassigned issues.
     *
     * @var list<string>
     */
    public array $assignee = [];

    /**
     * Ids of teams (get_project lists them): the issues given to one of them.
     *
     * @var list<int>
     */
    public array $assigneeTeam = [];

    /** Id of a user, or `me`. */
    public ?string $author = null;

    /**
     * Ids of categories (get_project lists them).
     *
     * @var list<int>
     */
    public array $tracker = [];

    /**
     * Ids of steps, `in_progress` (get_project lists those of each category); with `tracker`, the steps of those categories only.
     *
     * @var list<string>
     */
    public array $place = [];

    /**
     * Among low, normal, high and blocking.
     *
     * @var list<string>
     */
    public array $criticality = [];

    /**
     * Ids of releases, or `none` for the issues outside any.
     *
     * @var list<string>
     */
    public array $release = [];

    /** Also the finished issues. */
    public bool $closed = false;

    #[Assert\Choice(choices: ['key', 'title', 'project', 'tracker', 'place', 'criticality', 'assignee', 'updated'])]
    public ?string $sort = null;

    #[Assert\Choice(choices: ['asc', 'desc'])]
    public ?string $order = null;

    #[Assert\Positive]
    public int $page = 1;
}
