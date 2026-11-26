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

    /**
     * Whether a team holds a role on a project, directly or through its
     * workspace: what makes it one of its teams (ListProjectTeams).
     */
    public function holdsRoleOn(Team $team, Project $project): bool
    {
        return null !== $this->createQueryBuilder('a')
            ->select('a.id')
            ->where('a.team = :team')
            ->andWhere('a.project = :project OR a.workspace = :workspace')
            ->setParameter('team', $team)
            ->setParameter('project', $project)
            ->setParameter('workspace', $project->getWorkspace())
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOne(Team $team, Project|Workspace $scope): ?TeamAccess
    {
        return $this->findOneBy(['team' => $team, $scope instanceof Project ? 'project' : 'workspace' => $scope]);
    }
}
