<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Whoever the instance holds to a second factor and has none yet signs in, and can do nothing but set it up (or
 * sign out): every page of the application leads to the one that does. The API is not asked, its tokens are the
 * person's own to give.
 */
#[AsEventListener(KernelEvents::REQUEST, priority: 0)]
final readonly class TwoFactorEnrolment
{
    private const array ROUTES = ['app_account_two_factor', 'app_logout', '2fa_login', '2fa_login_check'];

    public function __construct(
        private Security $security,
        private TwoFactorPolicy $policy,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'main' !== $this->security->getFirewallConfig($request)?->getName()) {
            return;
        }
        if (\in_array($request->attributes->get('_route'), self::ROUTES, true)) {
            return;
        }

        $token = $this->security->getToken();
        $user = $token?->getUser();
        if (!$user instanceof User || $token instanceof TwoFactorTokenInterface || $user->hasTotp() || !$this->policy->requires($user)) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_account_two_factor')));
    }
}
