<?php

declare(strict_types=1);

namespace App\Message\Command\Team;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates a team, internal or external for good. Handled by
 * CreateTeamHandler, which returns the Team.
 */
final readonly class CreateTeam
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $name,
        public bool $internal,
    ) {
    }
}
