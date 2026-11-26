<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectTemplate;
use App\Entity\TemplateTeamAccess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TemplateTeamAccess>
 */
class TemplateTeamAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TemplateTeamAccess::class);
    }

    /**
     * The roles a template gives to teams, with the teams, by name.
     *
     * @return list<TemplateTeamAccess>
     */
    public function findByTemplate(ProjectTemplate $template): array
    {
        /* @var list<TemplateTeamAccess> */
        return $this->createQueryBuilder('a')
            ->addSelect('t')
            ->join('a.team', 't')
            ->where('a.template = :template')
            ->setParameter('template', $template)
            ->orderBy('t.name')
            ->getQuery()
            ->getResult();
    }
}
