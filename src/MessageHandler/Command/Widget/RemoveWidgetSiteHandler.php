<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Widget;

use App\Message\Command\Widget\RemoveWidgetSite;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\WidgetSiteRepository;
use App\Repository\WidgetTokenRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The site goes with the tokens given for it, in one statement whatever
 * their number.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RemoveWidgetSiteHandler
{
    public function __construct(
        private readonly WidgetSiteRepository $sites,
        private readonly WidgetTokenRepository $tokens,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RemoveWidgetSite $command): void
    {
        $site = $this->sites->find($command->siteId) ?? throw WidgetException::notFound('Site', $command->siteId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectConfigure, $site->getProject());

        $this->tokens->removeOfSite($site->getProject(), $site->getOrigin());
        $this->entityManager->remove($site);
    }
}
