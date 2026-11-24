<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * An error of the page an issue was reported from, as its report kept it.
 */
final readonly class SiteError
{
    public function __construct(
        /** `console` (written by the page with console.error), `error` (nobody caught it) or `rejection` (a promise nobody handled). */
        public string $kind,
        public string $message,
        /** The script and the line, when the browser said them. */
        public ?string $source,
        public ?string $at,
    ) {
    }
}
