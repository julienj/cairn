<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * An Ajax request of the page that failed (fetch, XMLHttpRequest), as the
 * report of an issue kept it: never what it carried.
 */
final readonly class SiteRequest
{
    public function __construct(
        public string $method,
        /** Without its parameters. */
        public string $url,
        /** The status of the answer, 0 when there was none (network, CORS). */
        public int $status,
        public ?string $at,
    ) {
    }
}
