<?php

declare(strict_types=1);

namespace App\Security;

use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;

/**
 * A secret on its way to an account, not saved yet: scheb's TOTP services read a user, so this stands in for the
 * one who is scanning it, to draw its QR code and to check the first code.
 */
final readonly class PendingTotp implements TwoFactorInterface
{
    public function __construct(
        private string $secret,
        private string $username,
    ) {
    }

    public function isTotpAuthenticationEnabled(): bool
    {
        return true;
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->username;
    }

    public function getTotpAuthenticationConfiguration(): TotpConfigurationInterface
    {
        return new TotpConfiguration($this->secret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }
}
