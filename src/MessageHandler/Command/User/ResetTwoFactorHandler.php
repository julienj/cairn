<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\ResetTwoFactor;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'command.bus')]
final class ResetTwoFactorHandler
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function __invoke(ResetTwoFactor $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);
        if (!$user->hasTotp()) {
            throw UserException::twoFactorNotOn();
        }

        $user->disableTotp();
    }
}
