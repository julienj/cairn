<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Message\Command\Team\DeleteTeam;
use App\Repository\IssueRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\TeamAccessRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A team stays while issues are assigned to it: give them to someone
 * first. Who is part of it, the roles it holds and what it follows are
 * current state: they go with the team, in statements AccessControl does not see.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTeamHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TeamMemberRepository $members,
        private readonly IssueRepository $issues,
        private readonly IssueTeamSubscriptionRepository $subscriptions,
        private readonly TeamAccessRepository $accesses,
        private readonly AccessControl $access,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteTeam $command): void
    {
        $team = $this->teams->find($command->teamId) ?? throw TeamException::notFound('Team', $command->teamId);

        $held = $this->issues->count(['assigneeTeam' => $team]);
        if ($held > 0) {
            throw TeamException::holdsIssues($team->getName(), $held);
        }

        $this->accesses->removeTeam($team);
        $this->subscriptions->removeTeam($team);
        $this->members->removeTeam($team);
        $this->access->reset();
        $this->entityManager->remove($team);
    }
}
