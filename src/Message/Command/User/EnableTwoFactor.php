<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Someone turns their second factor on: the secret of the app they scanned, a code of that app that proves it
 * works, and the backup codes they were just shown (kept as digests). Handled by EnableTwoFactorHandler.
 */
final readonly class EnableTwoFactor
{
    /**
     * @param list<string> $backupCodes
     */
    public function __construct(
        public int $userId,
        #[Assert\NotBlank]
        #[\SensitiveParameter]
        public string $secret,
        #[Assert\NotBlank]
        #[\SensitiveParameter]
        public string $code,
        #[Assert\Count(min: 1)]
        #[\SensitiveParameter]
        public array $backupCodes,
    ) {
    }
}
