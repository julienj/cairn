<?php

declare(strict_types=1);

namespace App\Message\Query\Release;

use App\Entity\Enum\PlaceStatus;
use App\Entity\Release;

/**
 * A release and the issues of it the viewer sees: counted by status, which
 * says how far it went, then, for those that are not finished, by the step
 * they stand at, in the order the instance declares its steps (a release
 * holds issues of several workflows, which share no path), and how many
 * of them are blocking or high. `late`: still open past the day it was
 * planned for.
 */
final readonly class ReleaseSummary
{
    /**
     * @param array<string, int> $byStatus status value => issues, every status present
     * @param list<ReleaseStep>  $steps    the steps of the unfinished issues
     */
    public function __construct(
        public Release $release,
        public array $byStatus,
        public array $steps,
        public int $blocking,
        public int $high,
        public bool $late,
    ) {
    }

    public function total(): int
    {
        return array_sum($this->byStatus);
    }

    /** The issues that are finished. */
    public function done(): int
    {
        return $this->byStatus[PlaceStatus::Closed->value];
    }

    /** The issues left to finish. */
    public function remaining(): int
    {
        return $this->total() - $this->done();
    }

    /** Share of the issues that are closed, from 0 to 100. */
    public function progress(): int
    {
        return 0 === $this->total() ? 0 : (int) round($this->done() / $this->total() * 100);
    }
}
