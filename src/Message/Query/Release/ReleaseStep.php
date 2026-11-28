<?php

declare(strict_types=1);

namespace App\Message\Query\Release;

use App\Config\Definition\PlaceDefinition;

/**
 * A step some unfinished issues of a release stand at, and how many.
 */
final readonly class ReleaseStep
{
    public function __construct(
        public PlaceDefinition $place,
        public int $issues,
    ) {
    }
}
