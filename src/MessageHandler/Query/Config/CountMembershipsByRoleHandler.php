<?php

declare(strict_types=1);

namespace App\MessageHandler\Query\Config;

use App\Message\Query\Config\CountMembershipsByRole;
use App\Repository\RoleUsage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'query.bus')]
final class CountMembershipsByRoleHandler
{
    public function __construct(
        private readonly RoleUsage $roleUsage,
    ) {
    }

    /**
     * @return array<string, int> role id => accesses, of people, of teams and of templates
     */
    public function __invoke(CountMembershipsByRole $query): array
    {
        return $this->roleUsage->byRole();
    }
}
