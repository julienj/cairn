<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Membership;
use App\Entity\TeamAccess;
use App\Entity\TemplateTeamAccess;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Where a role of the configuration is in use: the accesses of people,
 * those of teams, and those a project template gives to teams. The one
 * place that knows the three, for what a role may become and whether it
 * may go.
 */
final class RoleUsage
{
    /** What carries a role, and the property that says whether its holder is internal. */
    private const array HOLDERS = [
        [Membership::class, 'user'],
        [TeamAccess::class, 'team'],
        [TemplateTeamAccess::class, 'team'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * How many accesses carry a role, and how many of them belong to
     * external users or external teams.
     *
     * @return array{all: int, external: int}
     */
    public function of(string $role): array
    {
        $counts = ['all' => 0, 'external' => 0];
        foreach (self::HOLDERS as [$class, $holder]) {
            /** @var list<array{internal: bool, accesses: int|string}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('h.internal AS internal', 'COUNT(a.id) AS accesses')
                ->from($class, 'a')
                ->join('a.'.$holder, 'h')
                ->where('a.role = :role')
                ->setParameter('role', $role)
                ->groupBy('h.internal')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $counts['all'] += (int) $row['accesses'];
                if (!$row['internal']) {
                    $counts['external'] += (int) $row['accesses'];
                }
            }
        }

        return $counts;
    }

    /**
     * How many accesses carry each role, by its id.
     *
     * @return array<string, int>
     */
    public function byRole(): array
    {
        $counts = [];
        foreach (self::HOLDERS as [$class]) {
            /** @var list<array{role: string, accesses: int|string}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(a.role) AS role', 'COUNT(a.id) AS accesses')
                ->from($class, 'a')
                ->groupBy('a.role')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $counts[$row['role']] = ($counts[$row['role']] ?? 0) + (int) $row['accesses'];
            }
        }

        return $counts;
    }
}
