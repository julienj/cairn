<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Someone turns their second factor off, with a code of it: an open session left on a screen is not enough.
 * Handled by DisableTwoFactorHandler.
 */
final readonly class DisableTwoFactor
{
    public function __construct(
        public int $userId,
        #[Assert\NotBlank]
        #[\SensitiveParameter]
        public string $code,
    ) {
    }
}
