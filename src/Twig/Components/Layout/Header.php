<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Entity\Project;
use App\Entity\User;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Project\ProjectSummary;
use App\Message\QueryBus;
use App\Security\AccessControl;
use App\Security\Viewer;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The application header: the logo, the application tabs (home, my
 * issues, projects), the project the page belongs to when there is one,
 * which opens the list of the others, and the menu of the signed-in user,
 * which leads to the pages of their account and, for administrators,
 * into the administration. In the administration (`admin`) it
 * changes colour and leads back to the application instead.
 */
#[AsTwigComponent('Layout:Header')]
final class Header
{
    public ?Project $project = null;

    public bool $admin = false;

    /** The page has a sidebar of its own (the account pages): the burger opens it on small screens. */
    public bool $sidebar = false;

    public function __construct(
        private readonly Viewer $viewer,
        private readonly QueryBus $queries,
        private readonly AccessControl $access,
    ) {
    }

    /**
     * The projects to switch to, grouped by workspace.
     *
     * @return list<non-empty-list<ProjectSummary>>
     */
    public function getProjects(): array
    {
        return ProjectSummary::byWorkspace($this->queries->ask(new ListVisibleProjects($this->viewer->get())));
    }

    public function getUser(): User
    {
        return $this->viewer->get();
    }

    /**
     * Whether the menu leads to the tokens and the MCP server: for whoever may use the API, as the navigation of the account.
     */
    public function canUseApi(): bool
    {
        return $this->access->canUseApi($this->viewer->get());
    }
}
