<?php

declare(strict_types=1);

namespace App\Message\Command\Team;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Gives a team a role on a workspace or on a project, exactly one of the
 * two; a team that already holds a role there gets the new one instead.
 * Without an actor, the administration grants it; with one, the actor
 * needs `project.manage_members` and grants on a project only.
 * Handled by GrantTeamAccessHandler.
 */
#[Assert\Expression(
    '(this.workspaceId === null) !== (this.projectId === null)',
    message: 'An access is granted on a workspace or on a project.',
)]
final readonly class GrantTeamAccess
{
    public function __construct(
        public int $teamId,
        public ?int $workspaceId,
        public ?int $projectId,
        #[Assert\NotBlank]
        public string $role,
        public ?int $actorId = null,
    ) {
    }
}
