<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Entity\WidgetSite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WidgetSite>
 */
class WidgetSiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetSite::class);
    }

    /**
     * The sites of a project, by origin.
     *
     * @return list<WidgetSite>
     */
    public function findByProject(Project $project): array
    {
        return $this->findBy(['project' => $project], ['origin' => 'ASC']);
    }

    /**
     * The site of that origin, given as WidgetSite::normalise() writes it.
     */
    public function findOneByOrigin(Project $project, string $origin): ?WidgetSite
    {
        return $this->findOneBy(['project' => $project, 'origin' => $origin]);
    }
}
