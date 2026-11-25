<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

/**
 * What the reporter asked of their system, as the page saw it through its
 * media queries; null where the browser did not say.
 */
final readonly class SitePreferences
{
    public function __construct(
        /** light or dark. */
        public ?string $colorScheme,
        public ?bool $reducedMotion,
        /** more or less; null when nothing was asked. */
        public ?string $contrast,
        public ?bool $forcedColors,
        /** fine (a mouse), coarse (a touch screen) or none. */
        public ?string $pointer,
    ) {
    }
}
