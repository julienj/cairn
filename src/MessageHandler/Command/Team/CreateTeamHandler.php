<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Entity\Team;
use App\Message\Command\Team\CreateTeam;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class CreateTeamHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(CreateTeam $command): Team
    {
        if (null !== $this->teams->findOneNamed($command->name)) {
            throw TeamException::nameTaken(trim($command->name));
        }

        $team = new Team($command->name, $command->internal);
        $this->entityManager->persist($team);

        return $team;
    }
}
