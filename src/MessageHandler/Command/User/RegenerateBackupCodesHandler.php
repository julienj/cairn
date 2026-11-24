<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\RegenerateBackupCodes;
use App\Repository\UserRepository;
use App\Security\BackupCodes;
use App\Security\TwoFactorCodes;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class RegenerateBackupCodesHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorCodes $codes,
    ) {
    }

    public function __invoke(RegenerateBackupCodes $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);
        if (!$user->hasTotp()) {
            throw UserException::twoFactorNotOn();
        }
        if (!$this->codes->isValid($user, $command->code)) {
            throw UserException::wrongCode();
        }

        $user->replaceBackupCodes(BackupCodes::digests($command->backupCodes));
    }
}
