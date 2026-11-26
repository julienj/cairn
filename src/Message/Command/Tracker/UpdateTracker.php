<?php

declare(strict_types=1);

namespace App\Message\Command\Tracker;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes a tracker, PATCH style: a property left null keeps its value.
 * Its workflow never changes. A position moves it within its project's
 * list, the others making room. The actor needs `tracker.manage`.
 * Handled by UpdateTrackerHandler.
 */
final readonly class UpdateTracker
{
    public function __construct(
        public int $actorId,
        public int $trackerId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $name = null,
        /** An empty string clears it. */
        public ?string $description = null,
        public ?bool $private = null,
        public ?bool $archived = null,
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}
