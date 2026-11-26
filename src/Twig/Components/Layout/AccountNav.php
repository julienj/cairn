<?php

declare(strict_types=1);

namespace App\Twig\Components\Layout;

use App\Repository\WidgetTokenRepository;
use App\Security\AccessControl;
use App\Security\Viewer;
use Psr\Clock\ClockInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The sidebar of one's own account: profile, notifications and security,
 * then what lets programs act in one's name, shown to whoever may use
 * it (the API tokens today). The current entry is told from the current
 * route.
 */
#[AsTwigComponent('Layout:AccountNav')]
final class AccountNav
{
    public function __construct(
        private readonly Viewer $viewer,
        private readonly AccessControl $access,
        private readonly WidgetTokenRepository $widgetTokens,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Whether the account is linked to the widget of a site somewhere: the page that lists them shows then only.
     */
    public function hasConnectedSites(): bool
    {
        return $this->widgetTokens->hasUsable($this->viewer->get(), $this->clock->now());
    }

    public function canUseApi(): bool
    {
        return $this->access->canUseApi($this->viewer->get());
    }
}
