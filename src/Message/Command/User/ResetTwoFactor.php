<?php

declare(strict_types=1);

namespace App\Message\Command\User;

/**
 * The administration takes an account's second factor off, for someone who lost their phone: they sign
 * in with their password and set it up again. Handled by ResetTwoFactorHandler.
 */
final readonly class ResetTwoFactor
{
    public function __construct(public int $userId)
    {
    }
}
