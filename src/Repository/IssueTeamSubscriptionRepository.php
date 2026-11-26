<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use App\Entity\IssueTeamSubscription;
use App\Entity\Team;
use App\Entity\TeamAccess;
use App\Entity\TeamMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssueTeamSubscription>
 */
class IssueTeamSubscriptionRepository extends ServiceEntityRepository
{
    /**
     * A team follows an issue only while it holds a role on its project,
     * directly or through its workspace: asked each time, so a team that
     * loses its role stops following and follows again with one. For a
     * query that names the subscription `s` and binds `project` and
     * `workspace`.
     */
    private const string HOLDS_A_ROLE = 'EXISTS (SELECT 1 FROM %s a WHERE a.team = s.team AND (a.project = :project OR a.workspace = :workspace))';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IssueTeamSubscription::class);
    }

    public function findOne(Issue $issue, Team $team): ?IssueTeamSubscription
    {
        return $this->findOneBy(['issue' => $issue, 'team' => $team]);
    }

    /**
     * The teams following the issue, by name.
     *
     * @return list<Team>
     */
    public function findTeams(Issue $issue): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('t')
            ->from(Team::class, 't')
            ->join(IssueTeamSubscription::class, 's', 'WITH', 's.team = t')
            ->where('s.issue = :issue')
            ->andWhere(\sprintf(self::HOLDS_A_ROLE, TeamAccess::class))
            ->setParameter('issue', $issue)
            ->setParameter('project', $issue->getProject())
            ->setParameter('workspace', $issue->getProject()->getWorkspace())
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * The teams someone is part of that follow the issue, by name: through
     * them the person is written to without following it themselves.
     *
     * @return list<Team>
     */
    public function findTeamsOf(Issue $issue, User $user): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('t')
            ->from(Team::class, 't')
            ->join(IssueTeamSubscription::class, 's', 'WITH', 's.team = t')
            ->join(TeamMember::class, 'm', 'WITH', 'm.team = t')
            ->where('s.issue = :issue')
            ->andWhere('m.user = :user')
            ->andWhere(\sprintf(self::HOLDS_A_ROLE, TeamAccess::class))
            ->setParameter('issue', $issue)
            ->setParameter('user', $user)
            ->setParameter('project', $issue->getProject())
            ->setParameter('workspace', $issue->getProject()->getWorkspace())
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * The active members of the teams following the issue: who is part of
     * them now. Whether each one may see what happened is the caller's to
     * check.
     *
     * @return list<User>
     */
    public function findMembers(Issue $issue): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where(\sprintf('EXISTS (SELECT 1 FROM %s m JOIN %s s WITH s.team = m.team WHERE m.user = u AND s.issue = :issue AND '.self::HOLDS_A_ROLE.')', TeamMember::class, IssueTeamSubscription::class, TeamAccess::class))
            ->andWhere('u.active = true')
            ->setParameter('issue', $issue)
            ->setParameter('project', $issue->getProject())
            ->setParameter('workspace', $issue->getProject()->getWorkspace())
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->getQuery()
            ->getResult();
    }

    /**
     * Stops a team following anything, in one statement: the team is
     * being deleted, and it may follow a great many issues.
     */
    public function removeTeam(Team $team): void
    {
        $this->createQueryBuilder('s')->delete()->where('s.team = :team')->setParameter('team', $team)->getQuery()->execute();
    }
}
