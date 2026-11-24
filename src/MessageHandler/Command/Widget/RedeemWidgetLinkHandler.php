<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Widget;

use App\Entity\WidgetToken;
use App\Message\Command\Widget\RedeemWidgetLink;
use App\Repository\ProjectRepository;
use App\Repository\UserRepository;
use App\Repository\WidgetSiteRepository;
use App\Repository\WidgetTokenRepository;
use App\Security\AccessControl;
use App\Security\Permission;
use App\Widget\PendingLinks;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Everything the agreement said is checked again: minutes went by since
 * the account was linked. The tokens nobody can use any more are removed
 * on the way, so that the table holds what is in use.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class RedeemWidgetLinkHandler
{
    public function __construct(
        private readonly PendingLinks $links,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly WidgetSiteRepository $sites,
        private readonly WidgetTokenRepository $tokens,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(RedeemWidgetLink $command): ?string
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $command->verifier)) {
            return null;
        }
        $link = $this->links->take($command->verifier);
        if (null === $link || $link['project'] !== $command->projectId) {
            return null;
        }
        $user = $this->users->find($link['user']);
        $project = $this->projects->find($link['project']);
        if (null === $user || null === $project || !$user->isActive()
            || !$this->access->isGranted($user, Permission::IssueCreate, $project)
            || null === $this->sites->findOneByOrigin($project, $link['origin'])
        ) {
            return null;
        }

        $this->tokens->removeExpired($this->clock->now());
        $secret = WidgetToken::newSecret();
        $this->entityManager->persist(new WidgetToken($user, $project, $link['origin'], $secret));

        return $secret;
    }
}
