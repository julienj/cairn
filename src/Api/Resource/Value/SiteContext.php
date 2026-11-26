<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * One thing a site says of itself (its version, its environment, who is
 * signed in…), named as its pages chose: what they said, nothing more.
 */
final readonly class SiteContext
{
    public function __construct(
        public string $name,
        public string $value,
    ) {
    }
}
