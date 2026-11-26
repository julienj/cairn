<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use App\Message\Command\User\ResetPassword;
use App\Repository\WidgetTokenRepository;
use App\Security\PasswordLinkException;
use App\Security\PasswordTokens;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The token names the account and is consumed. A new password ends the
 * open sessions of the account (User::isEqualTo) and its remember-me
 * cookies (signed on the password). A link still out for an account since
 * told to sign in through the identity provider is worth nothing.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class ResetPasswordHandler
{
    public function __construct(
        private readonly PasswordTokens $tokens,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly WidgetTokenRepository $widgetTokens,
    ) {
    }

    public function __invoke(ResetPassword $command): void
    {
        $user = $this->tokens->consume($command->token);
        if (!$user->isActive() || $user->usesSso()) {
            throw PasswordLinkException::invalid();
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $command->plainPassword));
        // a password forgotten may be a password stolen: the account is linked on no site any more
        $this->widgetTokens->removeOfUser($user);
    }
}
