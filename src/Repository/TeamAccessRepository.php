<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Team;
use App\Entity\TeamAccess;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamAccess>
 */
class TeamAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamAccess::class);
    }

    /**
     * The roles someone holds through the teams they are part of.
     *
     * @return list<TeamAccess>
     */
    public function findByMember(User $user): array
    {
        /* @var list<TeamAccess> */
        return $this->createQueryBuilder('a')
            ->join(TeamMember::class, 'm', 'WITH', 'm.team = a.team')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * The roles granted to teams directly on a project, with the teams,
     * by name.
     *
     * @return list<TeamAccess>
     */
    public function findOnProject(Project $project): array
    {
        /* @var list<TeamAccess> */
        return $this->createQueryBuilder('a')
            ->addSelect('t')
            ->join('a.team', 't')
            ->where('a.project = :project')
            ->setParameter('project', $project)
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * The roles granted to teams on a workspace, with the teams, by name.
     *
     * @return list<TeamAccess>
     */
    public function findOnWorkspace(Workspace $workspace): array
    {
        /* @var list<TeamAccess> */
        return $this->createQueryBuilder('a')
            ->addSelect('t')
            ->join('a.team', 't')
            ->where('a.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * The roles of a team with where they apply, grouped by workspace:
     * the workspace first, then its projects by name.
     *
     * @return list<TeamAccess>
     */
    public function findOfTeam(Team $team): array
    {
        /* @var list<TeamAccess> */
        return $this->createQueryBuilder('a')
            ->addSelect('w', 'p', 'pw')
            ->leftJoin('a.workspace', 'w')
            ->leftJoin('a.project', 'p')
            ->leftJoin('p.workspace', 'pw')
            ->where('a.team = :team')
            ->setParameter('team', $team)
            ->addSelect('COALESCE(w.name, pw.name) AS HIDDEN workspaceName')
            ->addSelect('CASE WHEN p.id IS NULL THEN 0 ELSE 1 END AS HIDDEN projectOrder')
            ->orderBy('workspaceName')
            ->addOrderBy('projectOrder')
            ->addOrderBy('p.name')
            ->getQuery()
            ->getResult();
    }

    public function findOne(Team $team, Project|Workspace $scope): ?TeamAccess
    {
        return $this->findOneBy(['team' => $team, $scope instanceof Project ? 'project' : 'workspace' => $scope]);
    }

    /**
     * How many accesses of teams carry a role, and how many of them
     * belong to external teams: counted with those of people
     * (MembershipRepository::countHolding()).
     *
     * @return array{all: int, external: int}
     */
    public function countHolding(string $role): array
    {
        /** @var list<array{internal: bool, accesses: int|string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('t.internal AS internal', 'COUNT(a.id) AS accesses')
            ->join('a.team', 't')
            ->where('a.role = :role')
            ->setParameter('role', $role)
            ->groupBy('t.internal')
            ->getQuery()
            ->getArrayResult();

        $counts = ['all' => 0, 'external' => 0];
        foreach ($rows as $row) {
            $counts['all'] += (int) $row['accesses'];
            if (!$row['internal']) {
                $counts['external'] += (int) $row['accesses'];
            }
        }

        return $counts;
    }

    /**
     * Takes back every access of a team, in one statement: the team is
     * being deleted.
     */
    public function removeTeam(Team $team): void
    {
        $this->createQueryBuilder('a')->delete()->where('a.team = :team')->setParameter('team', $team)->getQuery()->execute();
    }
}
