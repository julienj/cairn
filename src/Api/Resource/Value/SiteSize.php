<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * A size in CSS pixels: the window of the browser, or the screen.
 */
final readonly class SiteSize
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
    }
}
