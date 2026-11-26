<?php

declare(strict_types=1);

namespace App\Message\Query\Config;

use App\Message\Query\Query;

/**
 * How many accesses carry each role, by its id: those of people and
 * those of teams.
 *
 * @implements Query<array<string, int>>
 */
final readonly class CountMembershipsByRole implements Query
{
}
