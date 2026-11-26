<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Automation\RuleReferences;
use App\Entity\IssueTeamSubscription;
use App\Entity\TeamAccess;
use App\Entity\TeamMember;
use App\Entity\TemplateTeamAccess;
use App\Message\Command\Team\DeleteTeam;
use App\Repository\IssueRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\TeamAccessRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\TemplateTeamAccessRepository;
use App\Security\AccessControl;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A team stays while issues are assigned to it or an automation rule
 * aims at it: give the issues to someone, change the rules first. Who is part of it, the roles it holds and what it follows are
 * current state: they go with the team, in statements AccessControl does not see.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTeamHandler
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TeamMemberRepository $members,
        private readonly IssueRepository $issues,
        private readonly TemplateTeamAccessRepository $templateAccesses,
        private readonly RuleReferences $rules,
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

        $rules = $this->rules->toTeam((int) $team->getId());
        if ([] !== $rules) {
            throw TeamException::namedByRules($team->getName(), $rules);
        }

        $this->accesses->removeTeam($team);
        $this->templateAccesses->removeTeam($team);
        $this->subscriptions->removeTeam($team);
        $this->members->removeTeam($team);
        $this->access->reset();
        // The rows went without the unit of work knowing: what it still holds of them must not be written back.
        foreach ([TeamAccess::class, TemplateTeamAccess::class, TeamMember::class, IssueTeamSubscription::class] as $class) {
            foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[$class] ?? [] as $held) {
                if (($held instanceof TeamAccess || $held instanceof TemplateTeamAccess || $held instanceof TeamMember || $held instanceof IssueTeamSubscription) && $held->getTeam() === $team) {
                    $this->entityManager->detach($held);
                }
            }
        }
        $this->entityManager->remove($team);
    }
}
