<?php

declare(strict_types=1);

namespace App\Message\Command\User;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Someone replaces their backup codes, with a code of the second factor: the ones they had no longer work.
 * Handled by RegenerateBackupCodesHandler.
 */
final readonly class RegenerateBackupCodes
{
    /**
     * @param list<string> $backupCodes
     */
    public function __construct(
        public int $userId,
        #[Assert\NotBlank]
        #[\SensitiveParameter]
        public string $code,
        #[Assert\Count(min: 1)]
        #[\SensitiveParameter]
        public array $backupCodes,
    ) {
    }
}
