<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Message\Command\Team\RevokeTeamAccess;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\TeamAccessRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RevokeTeamAccessHandler
{
    public function __construct(
        private readonly TeamAccessRepository $accesses,
        private readonly EntityManagerInterface $entityManager,
        private readonly ProjectActor $actor,
    ) {
    }

    public function __invoke(RevokeTeamAccess $command): void
    {
        $access = $this->accesses->find($command->accessId) ?? throw TeamException::notFound('Access', $command->accessId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::ProjectManageMembers, $access->getProject());
        $this->entityManager->remove($access);
    }
}
