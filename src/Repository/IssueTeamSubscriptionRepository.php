<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Issue;
use App\Entity\IssueTeamSubscription;
use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IssueTeamSubscription>
 */
class IssueTeamSubscriptionRepository extends ServiceEntityRepository
{
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
            ->setParameter('issue', $issue)
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
            ->where(\sprintf('EXISTS (SELECT 1 FROM %s m JOIN %s s WITH s.team = m.team WHERE m.user = u AND s.issue = :issue)', TeamMember::class, IssueTeamSubscription::class))
            ->andWhere('u.active = true')
            ->setParameter('issue', $issue)
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->getQuery()
            ->getResult();
    }

    /**
     * Stops a team following anything, in one statement: the team is
     * being deleted.
     */
    public function removeTeam(Team $team): void
    {
        $this->createQueryBuilder('s')->delete()->where('s.team = :team')->setParameter('team', $team)->getQuery()->execute();
    }
}
