<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Membership>
 */
class MembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Membership::class);
    }

    /**
     * @return list<Membership>
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user]);
    }

    /**
     * The roles granted directly on a project, with their users, by name.
     *
     * @return list<Membership>
     */
    public function findOnProject(Project $project): array
    {
        /* @var list<Membership> */
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.project = :project')
            ->setParameter('project', $project)
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->getQuery()
            ->getResult();
    }

    /**
     * The roles granted on a workspace, with their users, by name.
     *
     * @return list<Membership>
     */
    public function findOnWorkspace(Workspace $workspace): array
    {
        /* @var list<Membership> */
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('u.lastName')
            ->addOrderBy('u.firstName')
            ->getQuery()
            ->getResult();
    }

    /**
     * The referent of a role marked on a project, or on a workspace: the
     * mark is held by one membership at most there (SetReferent).
     */
    public function findReferent(Project|Workspace $scope, string $role): ?Membership
    {
        return $this->findOneBy([$scope instanceof Project ? 'project' : 'workspace' => $scope, 'role' => $role, 'referent' => true]);
    }

    /**
     * Every membership marked as referent that applies to a project, by
     * the role or the other: those of the project first, then those of
     * its workspace.
     *
     * @return list<Membership>
     */
    public function findReferentsApplyingTo(Project $project): array
    {
        /* @var list<Membership> */
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.referent = true')
            ->andWhere('m.project = :project OR m.workspace = :workspace')
            ->setParameter('project', $project)
            ->setParameter('workspace', $project->getWorkspace())
            ->addSelect('CASE WHEN m.project IS NULL THEN 1 ELSE 0 END AS HIDDEN scopeOrder')
            ->orderBy('scopeOrder')
            ->addOrderBy('m.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * The roles of a user with where they apply, grouped by workspace:
     * the workspace first, then its projects by name.
     *
     * @return list<Membership>
     */
    public function findOfUser(User $user): array
    {
        /* @var list<Membership> */
        return $this->createQueryBuilder('m')
            ->addSelect('w', 'p', 'pw')
            ->leftJoin('m.workspace', 'w')
            ->leftJoin('m.project', 'p')
            ->leftJoin('p.workspace', 'pw')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->addSelect('COALESCE(w.name, pw.name) AS HIDDEN workspaceName')
            ->addSelect('CASE WHEN p.id IS NULL THEN 0 ELSE 1 END AS HIDDEN projectOrder')
            ->orderBy('workspaceName')
            ->addOrderBy('projectOrder')
            ->addOrderBy('p.name')
            ->getQuery()
            ->getResult();
    }
}
