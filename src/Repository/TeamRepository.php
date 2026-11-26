<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\Team;
use App\Entity\TeamAccess;
use App\Entity\TeamMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Team>
 */
class TeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Team::class);
    }

    /**
     * Every team, by name.
     *
     * @return list<Team>
     */
    public function findAllByName(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }

    /**
     * The teams that hold a role on a project, directly or through its
     * workspace, by name: those an issue of the project may be given to.
     *
     * @return list<Team>
     */
    public function findOnProject(Project $project): array
    {
        return $this->createQueryBuilder('t')
            ->where(\sprintf('EXISTS (SELECT 1 FROM %s a WHERE a.team = t AND (a.project = :project OR a.workspace = :workspace))', TeamAccess::class))
            ->setParameter('project', $project)
            ->setParameter('workspace', $project->getWorkspace())
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * The teams someone is part of, by name.
     *
     * @return list<Team>
     */
    public function findOfMember(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where(\sprintf('EXISTS (SELECT 1 FROM %s m WHERE m.team = t AND m.user = :user)', TeamMember::class))
            ->setParameter('user', $user)
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }

    /**
     * The team of that name, whatever its case: two teams must not read
     * the same in a list.
     */
    public function findOneNamed(string $name): ?Team
    {
        return $this->createQueryBuilder('t')
            ->where('LOWER(t.name) = :name')
            ->setParameter('name', mb_strtolower(trim($name)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
