<?php

declare(strict_types=1);

namespace App\Twig\Components\Board;

use App\Config\Definition\PlaceDefinition;
use App\Entity\Issue;
use App\Entity\Tag;
use App\Entity\Tracker;
use App\Form\Issue\IssueListFilters;
use App\Message\QueryBus;
use App\Repository\Pagination;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\PlaceMove;
use App\Security\Viewer;
use App\Twig\Components\IssueList\ListChoices;
use App\Twig\Components\IssueList\ListScope;
use App\Workflow\WorkflowProvider;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * One column of a kanban: the issues of the tracker at one place, as the
 * viewer sees them and as the address of the list filters them (props the
 * browser cannot change). Board:Columns renders it lazily on the page, so a
 * column loads when it shows, and at once in the answer of the frame; it then grows by batches, never the whole board at
 * once. The closed places start folded: a narrow rail that counts the
 * issues only, unfolded on demand, where a card can still be dropped. The
 * cards come in the order of the column (IssueSort::Rank), which whoever
 * holds `issue.rank` changes by dragging. Each
 * card says which places the viewer may move it to
 * (AccessControl::movesFor()); Board:Move moves it
 * and the `board` controller keeps the columns right without asking them
 * again.
 */
#[AsLiveComponent('Board:Column')]
final class Column
{
    use DefaultActionTrait;

    /** Issues per batch. */
    public const int BATCH = 20;

    #[LiveProp]
    public Tracker $tracker;

    #[LiveProp]
    public string $placeId;

    /** @var array<string, mixed> the filters of the list, as its address says them (IssueListFilters::toQuery()) */
    #[LiveProp]
    public array $query = [];

    /** The batches shown, kept when the column redraws. */
    #[LiveProp(writable: true)]
    public int $batches = 1;

    /** Folded, the column is a rail that counts its issues and loads none. */
    #[LiveProp(writable: true)]
    public bool $open = true;

    /** @var Pagination<Issue>|null read once per render */
    private ?Pagination $issues = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
        private readonly WorkflowProvider $workflows,
        private readonly ListChoices $choices,
    ) {
    }

    public function getPlace(): PlaceDefinition
    {
        return $this->workflows->place($this->placeId);
    }

    /**
     * The issues shown, or, folded, a page of none that still counts them.
     *
     * @return Pagination<Issue>
     */
    public function getIssues(): Pagination
    {
        return $this->issues ??= $this->queries->ask($this->choices->searchColumn(
            ListScope::ofTracker($this->tracker),
            IssueListFilters::fromQuery($this->query),
            $this->placeId,
            $this->open ? min(200, self::BATCH * max(1, $this->batches)) : 1,
        ));
    }

    /**
     * The tags of the cards shown.
     *
     * @return array<int, list<Tag>> issue id => its tags
     */
    public function getTags(): array
    {
        return $this->choices->tagsOf($this->getIssues()->items);
    }

    public function getRemaining(): int
    {
        return $this->open ? max(0, $this->getIssues()->total - \count($this->getIssues()->items)) : 0;
    }

    /**
     * The places the viewer may drop the issue on.
     *
     * @return list<string>
     */
    public function dropsFor(Issue $issue): array
    {
        return PlaceMove::placeIds($this->access->movesFor($this->viewer->get(), $issue));
    }

    /**
     * Whether the viewer arranges the cards of a column by hand.
     */
    public function canRank(): bool
    {
        return $this->access->isGranted($this->viewer->get(), Permission::IssueRank, $this->tracker->getProject());
    }

    #[LiveAction]
    public function more(): void
    {
        $this->batches = min(10, $this->batches + 1);
    }

    #[LiveAction]
    public function toggle(): void
    {
        $this->open = !$this->open;
        $this->batches = 1;
    }
}
