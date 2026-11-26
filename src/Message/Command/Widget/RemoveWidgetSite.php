<?php

declare(strict_types=1);

namespace App\Message\Command\Widget;

/**
 * Removes a site from the widget of its project, with the tokens given
 * for it: whoever linked their account there is signed out of the widget.
 * The actor needs `widget.manage`.
 */
final readonly class RemoveWidgetSite
{
    public function __construct(
        public int $actorId,
        public int $siteId,
    ) {
    }
}
