<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamMember>
 */
class TeamMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamMember::class);
    }

    /**
     * The members of a team, by name, deactivated accounts included.
     *
     * @return list<TeamMember>
     */
    public function findOfTeam(Team $team): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->where('m.team = :team')
            ->setParameter('team', $team)
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->getQuery()
            ->getResult();
    }

    public function findOne(Team $team, User $user): ?TeamMember
    {
        return $this->findOneBy(['team' => $team, 'user' => $user]);
    }

    /**
     * The internal teams someone is part of, by name: what keeps an
     * account internal.
     *
     * @return list<Team>
     */
    public function findInternalTeamsOf(User $user): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('t')
            ->from(Team::class, 't')
            ->join(TeamMember::class, 'm', 'WITH', 'm.team = t')
            ->where('m.user = :user')
            ->andWhere('t.internal = true')
            ->setParameter('user', $user)
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * How many members each team has.
     *
     * @return array<int, int> team id => members, the teams that have some
     */
    public function countByTeam(): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('IDENTITY(m.team) AS team', 'COUNT(m.id) AS members')
            ->groupBy('m.team')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['team']] = (int) $row['members'];
        }

        return $counts;
    }
}
