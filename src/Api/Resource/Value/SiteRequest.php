<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * What failed on the network of the page, as the report of an issue kept
 * it: an Ajax request (fetch, XMLHttpRequest) or one of its files, never
 * what it carried.
 */
final readonly class SiteRequest
{
    public function __construct(
        /** ajax, or the kind of the file: image, script, stylesheet, font, media, other. */
        public string $kind,
        /** The method of an Ajax request, null for a file. */
        public ?string $method,
        /** Without its parameters. */
        public string $url,
        /** The status of the answer: 0 when an Ajax request got none (network, CORS), null when the browser did not give the status of a file. */
        public ?int $status,
        public ?string $at,
    ) {
    }
}
