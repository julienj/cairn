<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Message\Command\Team\UpdateTeam;
use App\Repository\TeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class UpdateTeamHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
    ) {
    }

    public function __invoke(UpdateTeam $command): void
    {
        $team = $this->teams->find($command->teamId) ?? throw TeamException::notFound('Team', $command->teamId);

        if (null !== $command->name) {
            $named = $this->teams->findOneNamed($command->name);
            if (null !== $named && $named !== $team) {
                throw TeamException::nameTaken(trim($command->name));
            }
            $team->rename($command->name);
        }
    }
}
