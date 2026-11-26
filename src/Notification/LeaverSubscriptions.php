<?php

declare(strict_types=1);

namespace App\Notification;

use App\Entity\Issue;
use App\Entity\IssueSubscription;
use App\Entity\IssueTeamSubscription;
use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\TeamAccess;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\ProjectRepository;
use App\Repository\TeamAccessRepository;
use App\Repository\TeamMemberRepository;
use App\Security\AccessControl;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whoever leaves a project stops following its issues: a person who no
 * longer holds any role on it, internal or not, and a team that no longer
 * holds one. The role of someone has several sources (their accesses,
 * those of their teams, on the project or on its workspace), so what was
 * taken back is noted when it goes and the role is asked again once the
 * change is written (`postFlush`, inside the transaction of the command):
 * someone who keeps a role by another way keeps following. Following
 * again is theirs to do, or the next comment, mention or assignment's.
 *
 * Only what is removed through the unit of work is heard: the bulk
 * deletions of an account, a project or a workspace take the
 * subscriptions with them by themselves.
 */
#[AsEntityListener(event: Events::postRemove, method: 'membershipRevoked', entity: Membership::class)]
#[AsEntityListener(event: Events::postRemove, method: 'teamAccessRevoked', entity: TeamAccess::class)]
#[AsEntityListener(event: Events::postRemove, method: 'teamMemberRemoved', entity: TeamMember::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class LeaverSubscriptions implements ResetInterface
{
    /** @var list<array{User, Project|Workspace}> people, and where an access of theirs was taken back */
    private array $people = [];

    /** @var list<array{Team, Project|Workspace}> teams, and where an access of theirs was taken back */
    private array $teams = [];

    /** @var list<array{User, Team}> who left which team */
    private array $leavers = [];

    public function __construct(
        private readonly AccessControl $access,
        private readonly ProjectRepository $projects,
        private readonly TeamAccessRepository $teamAccesses,
        private readonly TeamMemberRepository $teamMembers,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function membershipRevoked(Membership $membership): void
    {
        $scope = $membership->getProject() ?? $membership->getWorkspace();
        if (null !== $scope) {
            $this->people[] = [$membership->getUser(), $scope];
        }
    }

    public function teamAccessRevoked(TeamAccess $access): void
    {
        $scope = $access->getProject() ?? $access->getWorkspace();
        if (null !== $scope) {
            $this->teams[] = [$access->getTeam(), $scope];
        }
    }

    public function teamMemberRemoved(TeamMember $member): void
    {
        $this->leavers[] = [$member->getUser(), $member->getTeam()];
    }

    public function postFlush(): void
    {
        [$people, $teams, $leavers] = [$this->people, $this->teams, $this->leavers];
        $this->reset();
        if ([] === $people && [] === $teams && [] === $leavers) {
            return;
        }

        // Someone who left a team: wherever the team holds a role, or held one until now.
        foreach ($leavers as [$user, $team]) {
            // a team deleted with its members holds nothing any more: what it held was noted as it went
            foreach (null === $team->getId() ? [] : $this->teamAccesses->findBy(['team' => $team]) as $access) {
                $people[] = [$user, $access->getProject() ?? $access->getWorkspace() ?? throw new \LogicException('An access is on a project or a workspace.')];
            }
            foreach ($teams as [$revoked, $scope]) {
                if ($revoked === $team) {
                    $people[] = [$user, $scope];
                }
            }
        }
        // A team that lost an access: itself, and whoever is part of it.
        foreach ($teams as [$team, $scope]) {
            foreach (null === $team->getId() ? [] : $this->teamMembers->findOfTeam($team) as $member) {
                $people[] = [$member->getUser(), $scope];
            }
        }

        $this->access->reset();
        $done = [];
        foreach ($people as [$user, $scope]) {
            foreach ($this->projectsOf($scope) as $project) {
                $key = $user->getId().'-'.$project->getId();
                if (!isset($done[$key]) && null === $this->access->roleOn($user, $project)) {
                    $this->forget(IssueSubscription::class, 'user', (int) $user->getId(), $project);
                }
                $done[$key] = true;
            }
        }
        foreach ($teams as [$team, $scope]) {
            foreach ($this->projectsOf($scope) as $project) {
                // a deleted team took what it followed with it
                if (null !== $team->getId() && !$this->teamAccesses->holdsRoleOn($team, $project)) {
                    $this->forget(IssueTeamSubscription::class, 'team', (int) $team->getId(), $project);
                }
            }
        }
    }

    public function reset(): void
    {
        $this->people = [];
        $this->teams = [];
        $this->leavers = [];
    }

    /**
     * @return list<Project>
     */
    private function projectsOf(Project|Workspace $scope): array
    {
        return $scope instanceof Project ? [$scope] : $this->projects->findBy(['workspace' => $scope]);
    }

    /**
     * @param class-string $subscription
     */
    private function forget(string $subscription, string $follower, int $id, Project $project): void
    {
        $this->entityManager->createQuery(\sprintf('DELETE FROM %s s WHERE s.%s = :follower AND s.issue IN (SELECT i.id FROM %s i WHERE i.project = :project)', $subscription, $follower, Issue::class))
            ->execute(['follower' => $id, 'project' => $project->getId()]);
    }
}
