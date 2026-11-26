<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The code typed at the sign-in reaches scheb as `TwoFactorCodes` reads it everywhere else, without its spaces:
 * scheb remembers a code by what was typed and only then drops the spaces, so "123 456" after "123456" would be
 * another code to it, and good a second time. Before the firewall, which checks the code.
 */
#[AsEventListener(KernelEvents::REQUEST, priority: 16)]
final class TwoFactorCodeInput
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $code = $request->request->all()['_auth_code'] ?? null;
        if ('2fa_login_check' === $request->attributes->get('_route') && \is_string($code)) {
            $request->request->set('_auth_code', TwoFactorCodes::clean($code));
        }
    }
}
