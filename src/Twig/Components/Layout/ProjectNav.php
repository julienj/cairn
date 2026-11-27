<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Entity\Project;
use App\Entity\Tracker;
use App\Message\Query\Project\CountProjectIssues;
use App\Message\Query\Project\ListTrackers;
use App\Message\Query\Project\ProjectIssueCounts;
use App\Message\QueryBus;
use App\Repository\ReleaseRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The sidebar of a project: its issue lists and its releases, then its
 * trackers, each list with the open issues the viewer sees in it. The
 * current entry is told from the current route.
 */
#[AsTwigComponent('Layout:ProjectNav')]
final class ProjectNav
{
    public Project $project;

    /** The id of the tracker the page is about, when it is about one (the page of an issue). */
    public ?string $currentTracker = null;

    public function __construct(
        private readonly QueryBus $queries,
        private readonly ReleaseRepository $releases,
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
    ) {
    }

    /**
     * Private trackers are hidden from external users. Archived ones stay,
     * at their place: they take no new issue but keep theirs.
     *
     * @return list<Tracker>
     */
    public function getTrackers(): array
    {
        return $this->queries->ask(new ListTrackers($this->viewer->get(), $this->project, archived: true));
    }

    /**
     * What each list holds as it comes, not as the viewer last arranged it:
     * the view they keep of a list filters the page, never this count.
     */
    public function getCounts(): ProjectIssueCounts
    {
        return $this->queries->ask(new CountProjectIssues($this->viewer->get(), $this->project));
    }

    /**
     * Releases are optional: a project shows them once it has one, or to
     * whoever may create the first.
     */
    public function hasReleases(): bool
    {
        return $this->releases->hasReleases($this->project);
    }

    /**
     * Budgets are optional too: the entry is there on a project that has
     * some, for whoever may read them.
     */
    public function readsBudgets(): bool
    {
        return $this->access->canReadBudgets($this->viewer->get(), $this->project);
    }
}
