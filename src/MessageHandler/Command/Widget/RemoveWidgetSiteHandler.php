<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Widget;

use App\Message\Command\Widget\RemoveWidgetSite;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\WidgetSiteRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The tokens given for the site go with it: the database removes them.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RemoveWidgetSiteHandler
{
    public function __construct(
        private readonly WidgetSiteRepository $sites,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RemoveWidgetSite $command): void
    {
        $site = $this->sites->find($command->siteId) ?? throw WidgetException::notFound('Site', $command->siteId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::WidgetManage, $site->getProject());

        $this->entityManager->remove($site);
    }
}
