<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Config;

use App\Entity\Membership;
use App\Entity\TeamAccess;
use App\Message\Query\Config\CountMembershipsByRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class CountMembershipsByRoleHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, int> role id => accesses, of people and of teams
     */
    public function __invoke(CountMembershipsByRole $query): array
    {
        $counts = [];
        // What people hold, then what teams hold.
        foreach ([Membership::class, TeamAccess::class] as $class) {
            /** @var list<array{role: string, memberships: int|string}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('IDENTITY(m.role) AS role', 'COUNT(m.id) AS memberships')
                ->from($class, 'm')
                ->groupBy('m.role')
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $counts[$row['role']] = ($counts[$row['role']] ?? 0) + (int) $row['memberships'];
            }
        }

        return $counts;
    }
}
