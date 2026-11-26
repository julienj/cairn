<?php

declare(strict_types=1);

namespace App\Message\Command\Tracker;

/**
 * Deletes a tracker that holds no issue, with the automation rules
 * limited to it: a tracker that has issues is archived instead. The actor
 * needs `tracker.manage`. Handled by DeleteTrackerHandler.
 */
final readonly class DeleteTracker
{
    public function __construct(
        public int $actorId,
        public int $trackerId,
    ) {
    }
}
