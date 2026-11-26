<?php

declare(strict_types=1);

namespace App\Message\Query\Issue;

use App\Entity\Enum\Criticality;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Query\Query;
use App\Repository\Pagination;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Every issue list is this query: a project, a workspace, my issues, the
 * ones I created. Its caller builds it whole: the viewer and the scope
 * from where it stands, the filters, the sort and the grouping from the
 * request. `status` is for the application's own lists (the home page):
 * users filter by place.
 *
 * @implements Query<Pagination<Issue>>
 */
final readonly class SearchIssues implements Query
{
    /**
     * @param list<PlaceStatus>  $status
     * @param list<string>       $place        place ids; closed issues are then listed when their place is chosen
     * @param list<int>          $project      among the projects of the scope
     * @param list<int>          $tracker
     * @param list<int>          $assignee     user ids, 0 for unassigned: given to nobody, neither a person nor a team
     * @param list<int>          $team         team ids; with `assignee`, an issue is listed when it is given to one of them, person or team
     * @param ?int               $teamMemberId the issues given to a team this user is part of
     * @param list<Criticality>  $criticality
     * @param list<int>          $release      release ids, 0 for the issues in no release
     * @param list<int>          $tag          tag ids, 0 for the issues without any tag: an issue is listed when it carries one of them
     * @param ?string            $q            words of the title, a full key, or the start of a number
     * @param bool               $closed       list closed issues too (implied when `status` names closed or `place` is set)
     * @param IssueSort|null     $sort         the column to sort by, before the default order
     * @param IssueGrouping|null $group        groups the issues, and counts each group (Pagination::$groupTotals)
     * @param int                $perPage      issues per page; a kanban column grows its single page by batches
     */
    public function __construct(
        public User $viewer,
        public ?int $projectId = null,
        public ?int $workspaceId = null,
        public ?int $assigneeId = null,
        public ?int $authorId = null,
        public array $status = [],
        public array $place = [],
        public array $project = [],
        public array $tracker = [],
        public array $assignee = [],
        public array $criticality = [],
        public array $release = [],
        public array $tag = [],
        public ?string $q = null,
        public bool $closed = false,
        #[Assert\Positive]
        public int $page = 1,
        public ?IssueSort $sort = null,
        public \SortDirection $order = \SortDirection::Ascending,
        public ?IssueGrouping $group = null,
        #[Assert\Range(min: 1, max: 200)]
        public int $perPage = 50,
        public array $team = [],
        public ?int $teamMemberId = null,
    ) {
    }
}
