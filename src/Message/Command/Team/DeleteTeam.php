<?php

declare(strict_types=1);

namespace App\Message\Command\Team;

/**
 * Deletes a team no issue is assigned to; its members and its accesses
 * go with it. Handled by
 * DeleteTeamHandler.
 */
final readonly class DeleteTeam
{
    public function __construct(
        public int $teamId,
    ) {
    }
}
