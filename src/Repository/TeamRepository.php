<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
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
