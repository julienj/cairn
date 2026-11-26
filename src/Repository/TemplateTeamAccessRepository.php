<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectTemplate;
use App\Entity\Team;
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

    /**
     * How many accesses of templates carry a role, and how many of them
     * go to external teams: counted with those of people and of teams.
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
     * Takes a team out of every template, in one statement: the team is
     * being deleted.
     */
    public function removeTeam(Team $team): void
    {
        $this->createQueryBuilder('a')->delete()->where('a.team = :team')->setParameter('team', $team)->getQuery()->execute();
    }
}
