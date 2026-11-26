<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Widget;

use App\Message\Command\Widget\LinkWidget;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WidgetSiteRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Widget\PendingLinks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AsMessageHandler(bus: 'command.bus')]
final class LinkWidgetHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly WidgetSiteRepository $sites,
        private readonly AccessControl $access,
        private readonly PendingLinks $links,
    ) {
    }

    public function __invoke(LinkWidget $command): void
    {
        $actor = $this->users->find($command->actorId) ?? throw WidgetException::notFound('User', $command->actorId);
        $project = $this->projects->find($command->projectId) ?? throw WidgetException::notFound('Project', $command->projectId);
        if (!$this->access->isGranted($actor, Permission::IssueCreate, $project)) {
            throw new AccessDeniedException(\sprintf('User %d may not create issues in %s.', $command->actorId, $project->getIssuePrefix()));
        }
        if (null === $this->sites->findOneByOrigin($project, $command->origin)) {
            throw WidgetException::unknownSite($command->origin);
        }

        $this->links->leave($command->challenge, $command->actorId, $command->projectId, $command->origin);
    }
}
