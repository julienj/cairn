<?php

declare(strict_types=1);

namespace App\Message\Command\Team;

/**
 * Takes someone out of a team. Handled by RemoveTeamMemberHandler.
 */
final readonly class RemoveTeamMember
{
    public function __construct(
        public int $teamId,
        public int $userId,
    ) {
    }
}
