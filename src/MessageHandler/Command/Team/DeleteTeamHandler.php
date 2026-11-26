<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Message\Command\Team\DeleteTeam;
use App\Repository\TeamAccessRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Who is part of a team and the roles it holds are current state: they
 * go with the team, in statements AccessControl does not see.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTeamHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TeamMemberRepository $members,
        private readonly TeamAccessRepository $accesses,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteTeam $command): void
    {
        $team = $this->teams->find($command->teamId) ?? throw TeamException::notFound('Team', $command->teamId);

        $this->accesses->removeTeam($team);
        $this->members->removeTeam($team);
        $this->access->reset();
        $this->entityManager->remove($team);
    }
}
