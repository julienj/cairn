<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * How the page performed, as the browser measured it while it loaded:
 * times in milliseconds from the start of its last load, the CLS apart (a
 * score). Each is null where the browser does not take it.
 */
final readonly class SitePerformance
{
    public function __construct(
        /** Time to first byte. */
        public ?int $ttfb,
        public ?int $domContentLoaded,
        public ?int $load,
        /** Largest Contentful Paint. */
        public ?int $lcp,
        /** Cumulative Layout Shift. */
        public ?float $cls,
        /** Interaction to Next Paint: the slowest interaction of the visit. */
        public ?int $inp,
    ) {
    }
}
