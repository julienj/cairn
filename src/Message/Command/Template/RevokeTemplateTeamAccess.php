<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

/**
 * A project template no longer gives a team its role: the projects made
 * from it since keep what they were given. Handled by
 * RevokeTemplateTeamAccessHandler.
 */
final readonly class RevokeTemplateTeamAccess
{
    public function __construct(
        public int $accessId,
    ) {
    }
}
