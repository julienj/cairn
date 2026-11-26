<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\ChangePassword;
use App\Repository\UserRepository;
use App\Repository\WidgetTokenRepository;
use App\Security\PasswordTokens;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The current password is checked first. The new one ends the open
 * sessions of the account (User::isEqualTo) and its remember-me cookies;
 * the caller signs the person in again on the session they asked from.
 * The reset links still out are dropped with the password they could
 * replace.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ChangePasswordHandler
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly PasswordTokens $tokens,
        private readonly WidgetTokenRepository $widgetTokens,
    ) {
    }

    public function __invoke(ChangePassword $command): void
    {
        $user = $this->users->find($command->userId) ?? throw UserException::notFound($command->userId);
        if (!$user->hasPassword() || !$this->passwordHasher->isPasswordValid($user, $command->currentPassword)) {
            throw UserException::wrongPassword();
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));
        $this->tokens->forget($user);
        // whoever held the old password holds nothing of the account any more, on the sites it was linked on either
        $this->widgetTokens->removeOfUser($user);
    }
}
