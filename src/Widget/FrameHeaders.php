<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Says who may frame an answer of the widget: the site the frame was
 * asked for, which its controller names on the request (ANCESTOR), and
 * nobody for everything else of the zone, which is only ever fetched by
 * that frame. The rest of the application is never framed
 * (nelmio_security), and that rule lets this zone alone so that this one
 * speaks for it.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class FrameHeaders
{
    /** The request attribute holding the origin that may frame the answer. */
    public const string ANCESTOR = '_widget_frame_ancestor';

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/widget/')) {
            return;
        }
        $ancestor = $request->attributes->get(self::ANCESTOR);

        $event->getResponse()->headers->set('Content-Security-Policy', 'frame-ancestors '.(\is_string($ancestor) ? $ancestor : "'none'"));
    }
}
