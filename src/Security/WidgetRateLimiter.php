<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Keeps the two doors of the widget that cost something to their pace
 * (config/packages/rate_limiter.yaml): the files an account sends, by
 * account whatever the number of browsers it is linked in, and the
 * exchange that links an account, which is public, by address. Beyond it
 * the request is refused with 429 and the time to wait. Asking whether a
 * site is known is left alone: every visitor of a site being reviewed
 * asks, from the same address, and it costs one read. Runs right after
 * the firewall, which named the token.
 */
final class WidgetRateLimiter
{
    public function __construct(
        #[Target('widget_upload.limiter')]
        private readonly RateLimiterFactoryInterface $uploads,
        #[Target('widget_link.limiter')]
        private readonly RateLimiterFactoryInterface $links,
        private readonly ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
    public function limit(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()) {
            return;
        }
        $token = WidgetTokenHandler::tokenOf($request);
        $limiter = match ($request->attributes->get('_route')) {
            'app_widget_attachment' => null === $token ? null : $this->uploads->create((string) $token->getUser()->getId()),
            'app_widget_token' => $this->links->create((string) $request->getClientIp()),
            default => null,
        };
        $limit = $limiter?->consume();
        if (null !== $limit && !$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - $this->clock->now()->getTimestamp()));
        }
    }
}
