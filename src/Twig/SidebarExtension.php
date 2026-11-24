<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Whether the project navigation is folded away on a wide screen, kept by
 * the browser in a cookie that the `sidebar` controller writes: the server
 * renders <html> with it, so that a page never shows the navigation before
 * hiding it.
 */
final class SidebarExtension
{
    public const string COOKIE = 'cairn_sidebar';

    public function __construct(
        private readonly RequestStack $requests,
    ) {
    }

    #[AsTwigFunction('sidebar_collapsed')]
    public function collapsed(): bool
    {
        return 'collapsed' === $this->requests->getMainRequest()?->cookies->get(self::COOKIE);
    }
}
