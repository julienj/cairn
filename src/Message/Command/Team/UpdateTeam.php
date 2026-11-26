<?php

declare(strict_types=1);

namespace App\Message\Command\Team;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a team, PATCH style: a property left null keeps its value.
 * Whether it is internal never changes. Handled by UpdateTeamHandler.
 */
final readonly class UpdateTeam
{
    public function __construct(
        public int $teamId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $name = null,
    ) {
    }
}
