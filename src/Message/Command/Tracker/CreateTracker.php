<?php

declare(strict_types=1);

namespace App\Message\Command\Tracker;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Creates a tracker at the end of its project's list, bound for good to a
 * workflow of the configuration. The actor needs `tracker.manage`;
 * without one, the administration acts (an import). Handled by CreateTrackerHandler, which returns the Tracker.
 */
final readonly class CreateTracker
{
    public function __construct(
        public ?int $actorId,
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $name,
        #[Assert\NotBlank]
        public string $workflowId,
        public ?string $description = null,
        public bool $private = false,
    ) {
    }
}
