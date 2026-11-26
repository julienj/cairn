<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;

/**
 * Checks a code outside the sign-in, where an account proves it holds the second factor before changing it: the
 * code of its app, or, while it is being set up, the code of the secret it just scanned. The
 * sign-in is scheb's.
 */
final readonly class TwoFactorCodes
{
    public function __construct(private TotpAuthenticatorInterface $totp)
    {
    }

    public function isValid(User $user, string $code): bool
    {
        return $user->isTotpAuthenticationEnabled() && $this->totp->checkCode($user, $this->clean($code));
    }

    public function isValidForSecret(string $secret, string $code): bool
    {
        return $this->totp->checkCode(new PendingTotp($secret, 'pending'), $this->clean($code));
    }

    private function clean(string $code): string
    {
        return (string) preg_replace('/\s+/', '', $code);
    }
}
