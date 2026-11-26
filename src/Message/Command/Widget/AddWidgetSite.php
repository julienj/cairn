<?php

declare(strict_types=1);

namespace App\Message\Command\Widget;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Declares a site the widget of a project is shown on, by its address:
 * only its origin is kept. The actor needs `widget.manage`. Handled
 * by AddWidgetSiteHandler, which returns the WidgetSite.
 */
final readonly class AddWidgetSite
{
    public function __construct(
        public int $actorId,
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 255)]
        public string $address,
    ) {
    }
}
