<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Entity\TeamMember;
use App\Message\Command\Team\AddTeamMember;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An internal team takes internal users only, an external team anyone;
 * a deactivated account joins no team.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class AddTeamMemberHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TeamMemberRepository $members,
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(AddTeamMember $command): void
    {
        $team = $this->teams->find($command->teamId) ?? throw TeamException::notFound('Team', $command->teamId);
        $user = $this->users->find($command->userId) ?? throw TeamException::notFound('User', $command->userId);
        if (!$user->isActive()) {
            throw TeamException::deactivatedMember($user->getFullName());
        }
        if ($team->isInternal() && !$user->isInternal()) {
            throw TeamException::externalMember($user->getFullName(), $team->getName());
        }

        if (null === $this->members->findOne($team, $user)) {
            $this->entityManager->persist(new TeamMember($team, $user));
        }
    }
}
