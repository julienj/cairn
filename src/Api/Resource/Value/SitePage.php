<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * A page of the site: the one an issue was reported from, or one seen
 * before it in the same tab.
 */
final readonly class SitePage
{
    public function __construct(
        /** Without its parameters. */
        public string $url,
        public ?string $title,
        public ?string $at = null,
    ) {
    }
}
