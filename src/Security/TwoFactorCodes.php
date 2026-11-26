<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\Authentication\Exception\ReusedTwoFactorCodeException;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Checks a code outside the sign-in, where an account proves it holds the second factor before changing it: the
 * code of its app, or, while it is being set up, the code of the secret it just scanned. A code is good once for
 * the whole application: the events are the ones scheb sends at the sign-in, so the same cache remembers it, and one
 * that signed someone in (or set it up) does not turn it off a few seconds later.
 */
final readonly class TwoFactorCodes
{
    public function __construct(
        private TotpAuthenticatorInterface $totp,
        private EventDispatcherInterface $events,
    ) {
    }

    public function isValid(User $user, string $code): bool
    {
        return $user->isTotpAuthenticationEnabled() && $this->check($user, $user, $this->clean($code));
    }

    public function isValidForSecret(User $user, string $secret, string $code): bool
    {
        return $this->check($user, new PendingTotp($secret, $user->getUserIdentifier()), $this->clean($code));
    }

    private function check(User $user, TwoFactorInterface $totpUser, string $code): bool
    {
        try {
            $this->events->dispatch(new TwoFactorCodeEvent($user, $code), TwoFactorAuthenticationEvents::CHECK);
        } catch (ReusedTwoFactorCodeException) {
            return false;
        }
        if (!$this->totp->checkCode($totpUser, $code)) {
            return false;
        }
        $this->events->dispatch(new TwoFactorCodeEvent($user, $code), TwoFactorAuthenticationEvents::CODE_VALID);

        return true;
    }

    private function clean(string $code): string
    {
        return (string) preg_replace('/\s+/', '', $code);
    }
}
