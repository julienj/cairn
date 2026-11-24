<?php

declare(strict_types=1);

namespace App\Security;

use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

use function Symfony\Component\Clock\now;

/**
 * The six digits of a code are guessed in a million tries: whoever knows the password gets a few attempts per
 * account, counted from the one that is checked and forgotten at the first that is right, as the login throttling
 * does.
 */
final readonly class TwoFactorThrottling
{
    public function __construct(
        #[Target('two_factor.limiter')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[AsEventListener(TwoFactorAuthenticationEvents::ATTEMPT)]
    public function attempt(TwoFactorAuthenticationEvent $event): void
    {
        $limit = $this->limiter->create($event->getToken()->getUserIdentifier())->consume();
        if (!$limit->isAccepted()) {
            // The key is in the catalogue of the bundle's own domain, the one its form reads (translations/SchebTwoFactorBundle.*).
            throw new CustomUserMessageAuthenticationException('auth.too_many_attempts', ['%minutes%' => max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - now()->getTimestamp()) / 60))]);
        }
    }

    #[AsEventListener(TwoFactorAuthenticationEvents::SUCCESS)]
    public function success(TwoFactorAuthenticationEvent $event): void
    {
        $this->limiter->create($event->getToken()->getUserIdentifier())->reset();
    }
}
