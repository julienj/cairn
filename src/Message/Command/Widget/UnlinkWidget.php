<?php

declare(strict_types=1);

namespace App\Message\Command\Widget;

/**
 * The actor takes their account back from the widget of a site, in one
 * browser: the token given there is deleted, and that browser has to
 * link an account again. Nothing else changes. Handled by
 * UnlinkWidgetHandler.
 */
final readonly class UnlinkWidget
{
    public function __construct(
        public int $actorId,
        public int $tokenId,
    ) {
    }
}
