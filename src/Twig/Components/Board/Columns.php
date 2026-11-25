<?php

declare(strict_types=1);

namespace App\Twig\Components\Board;

use App\Config\Definition\PlaceDefinition;
use App\Form\Issue\IssueListFilters;
use App\Twig\Components\IssueList\ListScope;
use App\Workflow\WorkflowProvider;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The kanban of a tracker, the other view of its list: one column per
 * place of its workflow, filtered like the list, and the component that
 * moves cards. It loads no issue itself: on the page each Board:Column
 * does when it shows, so the page is never slowed by the board; when only
 * the frame of the list is asked for (a filter changed), the columns are
 * drawn in that answer, each its first batch, so the board the frame
 * replaces gives way to the next one at once rather than to placeholders
 * that fill one by one.
 */
#[AsTwigComponent('Board:Columns')]
final class Columns
{
    public ListScope $scope;

    public IssueListFilters $filters;

    /** Whether the columns are drawn now rather than when they show. */
    public bool $eager = false;

    public function __construct(
        private readonly WorkflowProvider $workflows,
    ) {
    }

    /**
     * @return list<PlaceDefinition>
     */
    public function getPlaces(): array
    {
        $tracker = $this->scope->tracker ?? throw new \LogicException('Only the list of a tracker has a kanban.');

        return $this->workflows->places($tracker->getWorkflowId());
    }
}
