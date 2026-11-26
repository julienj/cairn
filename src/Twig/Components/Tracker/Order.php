<?php

declare(strict_types=1);

namespace App\Twig\Components\Tracker;

use App\Config\ConfigRegistry;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Message\Command\Tracker\UpdateTracker;
use App\Message\CommandBus;
use App\Repository\IssueRepository;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Every tracker of a project, deactivated ones included, in the order of
 * the creation form and the menu: dragging one to another place moves it
 * (the `sortable` controller calls `move`). Each links to its settings.
 */
#[AsLiveComponent('Tracker:Order')]
final class Order
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;

    #[LiveProp]
    public Project $project;

    /** The tracker whose settings the page shows, if any. */
    #[LiveProp]
    public ?int $current = null;

    public function __construct(
        private readonly TrackerRepository $trackers,
        private readonly IssueRepository $issues,
        private readonly ConfigRegistry $config,
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The list is drawn, and redrawn, only for who manages the trackers
     * of the project, whatever page or request holds it. The command
     * guards the writing.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        if (!$this->access->isGranted($this->viewer->get(), Permission::TrackerManage, $this->project)) {
            throw new AccessDeniedException('Arranging the trackers needs tracker.manage.');
        }
    }

    /**
     * @return list<Tracker>
     */
    public function getTrackers(): array
    {
        return $this->trackers->findByProject($this->project, includeArchived: true);
    }

    /**
     * @return array<int, int> tracker id => issues
     */
    public function getIssueCounts(): array
    {
        return $this->issues->countByTracker($this->project);
    }

    /**
     * @return array<string, string> workflow id => label; a workflow the configuration dropped is missing
     */
    public function getWorkflowLabels(): array
    {
        return $this->config->definition()->workflowLabels();
    }

    #[LiveAction]
    public function move(CommandBus $commands, #[LiveArg] int $tracker, #[LiveArg] int $position): void
    {
        $this->error = null;
        if ($this->tryCommand($commands, new UpdateTracker((int) $this->viewer->get()->getId(), $tracker, position: $position))) {
            $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans('project.settings.tracker.moved')]);
        }
    }
}
