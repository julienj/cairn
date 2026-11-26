<?php

declare(strict_types=1);

namespace App\Message\Command\Issue;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A member of a team stops it following an issue, for everyone of the
 * team; the next time the issue is given to the team, it follows it
 * again. Idempotent. Handled by UnfollowIssueAsTeamHandler.
 */
final readonly class UnfollowIssueAsTeam
{
    public function __construct(
        public int $actorId,
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $issueKey,
        public int $teamId,
    ) {
    }
}
