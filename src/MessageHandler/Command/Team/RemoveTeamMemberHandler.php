<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Message\Command\Team\RemoveTeamMember;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RemoveTeamMemberHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TeamMemberRepository $members,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RemoveTeamMember $command): void
    {
        $team = $this->teams->find($command->teamId) ?? throw TeamException::notFound('Team', $command->teamId);
        $user = $this->users->find($command->userId) ?? throw TeamException::notFound('User', $command->userId);

        $member = $this->members->findOne($team, $user);
        if (null !== $member) {
            $this->entityManager->remove($member);
        }
    }
}
