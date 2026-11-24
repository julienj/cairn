<?php

declare(strict_types=1);

namespace App\Message\Command\Widget;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The actor links their account to the widget of a project on one site:
 * an agreement is left for a few minutes under the challenge the widget
 * chose, which only the widget that knows its secret can redeem
 * (RedeemWidgetLink). The actor needs `issue.create` on the project, and
 * the site must be one of the project.
 */
final readonly class LinkWidget
{
    public function __construct(
        public int $actorId,
        public int $projectId,
        public string $origin,
        #[Assert\Regex('/^[0-9a-f]{64}$/')]
        public string $challenge,
    ) {
    }
}
