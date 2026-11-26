<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use App\Automation\RuleReferences;
use App\Message\Command\Team\DeleteTeam;
use App\Repository\IssueRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\TeamAccessRepository;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use App\Repository\TemplateTeamAccessRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A team stays while issues are assigned to it or an automation rule
 * aims at it: give the issues to someone, change the rules first. Who is
 * part of it, the roles it holds or a template gives it and what it
 * follows are current state: they go with the team.
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

        // Few rows each, removed one by one so that what listens to them (AccessControl) hears it.
        foreach ([$this->accesses, $this->templateAccesses, $this->subscriptions, $this->members] as $repository) {
            foreach ($repository->findBy(['team' => $team]) as $row) {
                $this->entityManager->remove($row);
            }
        }
        $this->entityManager->remove($team);
    }
}
