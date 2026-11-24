<?php

declare(strict_types=1);

namespace App\Message\Command\Widget;

/**
 * The widget of a project redeems the agreement left under the challenge
 * of its secret, once. Handled by RedeemWidgetLinkHandler, which returns
 * the secret of the new token, or null while nobody agreed.
 */
final readonly class RedeemWidgetLink
{
    public function __construct(
        public int $projectId,
        #[\SensitiveParameter]
        public string $verifier,
    ) {
    }
}
