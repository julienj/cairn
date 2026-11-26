<?php

declare(strict_types=1);

namespace App\Message\Command\Team;

/**
 * Takes back the role of a team on a workspace or a project. Without an
 * actor, the administration takes it back; with one, the actor needs
 * `project.manage_members` and takes back roles held on a project only.
 * Handled by RevokeTeamAccessHandler.
 */
final readonly class RevokeTeamAccess
{
    public function __construct(
        public int $accessId,
        public ?int $actorId = null,
    ) {
    }
}
