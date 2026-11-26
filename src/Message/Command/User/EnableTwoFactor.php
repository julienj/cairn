<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Someone turns their second factor on: the secret of the app they scanned and a code of that app that proves it
 * works. Handled by EnableTwoFactorHandler.
 */
final readonly class EnableTwoFactor
{
    public function __construct(
        public int $userId,
        #[Assert\NotBlank]
        #[\SensitiveParameter]
        public string $secret,
        #[Assert\NotBlank]
        #[\SensitiveParameter]
        public string $code,
    ) {
    }
}
