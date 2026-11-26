<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Issue;

use App\Message\Command\Issue\UnfollowIssueAsTeam;
use App\Notification\SubscriptionRules;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Whoever is part of the team speaks for it: any member unsubscribes it,
 * and nobody else does.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class UnfollowIssueAsTeamHandler
{
    public function __construct(
        private readonly IssueHandlerSupport $support,
        private readonly SubscriptionRules $subscriptions,
        private readonly TeamRepository $teams,
        private readonly TeamMemberRepository $members,
    ) {
    }

    public function __invoke(UnfollowIssueAsTeam $command): void
    {
        $actor = $this->support->actor($command->actorId);
        $issue = $this->support->visibleIssue($actor, $command->issueKey);
        $team = $this->teams->find($command->teamId) ?? throw IssueException::notFound('Team', $command->teamId);
        if (null === $this->members->findOne($team, $actor)) {
            throw new AccessDeniedException('Only a member of the team stops it following an issue.');
        }

        $this->subscriptions->unsubscribeTeam($issue, $team);
    }
}
