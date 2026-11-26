<?php

declare(strict_types=1);

namespace App\Message\Command\Template;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Has a project template give a team a role on the projects made from
 * it; a team the template already names gets the new role instead.
 * Handled by GrantTemplateTeamAccessHandler.
 */
final readonly class GrantTemplateTeamAccess
{
    public function __construct(
        public int $templateId,
        public int $teamId,
        #[Assert\NotBlank]
        public string $role,
    ) {
    }
}
