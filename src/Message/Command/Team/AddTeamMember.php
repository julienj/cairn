<?php

declare(strict_types=1);

namespace App\Message\Command\Team;

/**
 * Puts someone in a team; whoever is already part of it stays so.
 * Handled by AddTeamMemberHandler.
 */
final readonly class AddTeamMember
{
    public function __construct(
        public int $teamId,
        public int $userId,
    ) {
    }
}
