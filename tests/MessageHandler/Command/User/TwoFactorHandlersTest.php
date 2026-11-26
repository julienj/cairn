<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\User;

use App\Entity\User;
use App\Message\Command\User\DisableTwoFactor;
use App\Message\Command\User\EnableTwoFactor;
use App\Message\Command\User\ResetTwoFactor;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Tests\KernelHelpers;
use App\Tests\Security\TwoFactorHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

/**
 * The commands of the second factor: turning it on with the code of the secret that was scanned, turning it off
 * with a code of it, and the reset of the administration.
 */
final class TwoFactorHandlersTest extends KernelTestCase
{
    use KernelHelpers;
    use TwoFactorHelpers;

    private CommandBus $commands;

    protected function setUp(): void
    {
        $this->start();
        self::applyConfiguration();
    }

    /**
     * Boots the kernel, again when the test changed the environment the instance reads when it starts.
     */
    private function start(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        self::awaitSafeWindow();
        $this->commands = self::commandBus();
    }

    private function user(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user = new User($email, 'Claire', 'Test'));
        $entityManager->flush();

        return $user;
    }

    public function testTheCodeOfTheScannedSecretTurnsItOn(): void
    {
        $claire = $this->user('claire@client.test');
        $secret = self::totpSecret();

        $this->commands->dispatch(new EnableTwoFactor((int) $claire->getId(), $secret, self::totpCode($secret)));

        self::assertTrue($claire->hasTotp());
        self::assertSame($secret, $claire->getTotpSecret());
    }

    public function testAWrongCodeTurnsNothingOn(): void
    {
        $claire = $this->user('claire@client.test');

        try {
            $this->commands->dispatch(new EnableTwoFactor((int) $claire->getId(), self::totpSecret(), '000000'));
            self::fail('A code that is not the secret\'s should be refused.');
        } catch (UserException $e) {
            self::assertStringContainsString('not the one of the second factor', $e->getMessage());
        }

        self::assertFalse($claire->hasTotp());
    }

    public function testItIsNotTurnedOnTwice(): void
    {
        $claire = $this->user('claire@client.test');
        $this->enableTwoFactorOn($claire, self::totpSecret());
        $other = self::totpSecret();

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('already has a second factor');
        $this->commands->dispatch(new EnableTwoFactor((int) $claire->getId(), $other, self::totpCode($other)));
    }

    public function testAnAccountOfTheIdentityProviderCannotTurnItOn(): void
    {
        $claire = $this->user('claire@client.test');
        $claire->signInWithSso();
        $secret = self::totpSecret();

        try {
            $this->commands->dispatch(new EnableTwoFactor((int) $claire->getId(), $secret, self::totpCode($secret)));
            self::fail('The provider has its own second factor.');
        } catch (UserException $e) {
            self::assertStringContainsString('identity provider', $e->getMessage());
        }

        self::assertFalse($claire->hasTotp());
    }

    public function testASecretAndACodeAreAsked(): void
    {
        $claire = $this->user('claire@client.test');

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new EnableTwoFactor((int) $claire->getId(), 'JBSWY3DPEHPK3PXP', ''));
    }

    public function testItIsTurnedOffWithACodeOfTheApp(): void
    {
        $claire = $this->user('claire@client.test');
        $secret = self::totpSecret();
        $this->enableTwoFactorOn($claire, $secret);

        $this->commands->dispatch(new DisableTwoFactor((int) $claire->getId(), self::totpCode($secret)));

        self::assertFalse($claire->hasTotp());
    }

    public function testItIsNotTurnedOffWithAWrongCode(): void
    {
        $claire = $this->user('claire@client.test');
        $this->enableTwoFactorOn($claire, self::totpSecret());

        $this->expectException(UserException::class);
        try {
            $this->commands->dispatch(new DisableTwoFactor((int) $claire->getId(), '000000'));
        } finally {
            self::assertTrue($claire->hasTotp());
        }
    }

    public function testItIsNotTurnedOffWhenThereIsNone(): void
    {
        $claire = $this->user('claire@client.test');

        $this->expectException(UserException::class);
        $this->expectExceptionMessage('has no second factor');
        $this->commands->dispatch(new DisableTwoFactor((int) $claire->getId(), '000000'));
    }

    public function testWhoTheInstanceHoldsToItCannotTurnItOff(): void
    {
        $this->requireTwoFactor('all');
        $this->start();
        $claire = $this->user('claire@client.test');
        $secret = self::totpSecret();
        $this->enableTwoFactorOn($claire, $secret);

        try {
            $this->commands->dispatch(new DisableTwoFactor((int) $claire->getId(), self::totpCode($secret)));
            self::fail('The instance requires it.');
        } catch (UserException $e) {
            self::assertStringContainsString('holds this account to a second factor', $e->getMessage());
        }

        self::assertTrue($claire->hasTotp());
    }

    public function testTheAdministrationResetsItWithoutACode(): void
    {
        $claire = $this->user('claire@client.test');
        $this->enableTwoFactorOn($claire, self::totpSecret());

        $this->commands->dispatch(new ResetTwoFactor((int) $claire->getId()));

        self::assertFalse($claire->hasTotp());
    }

    public function testTheResetOfAnAccountWithoutOneIsRefused(): void
    {
        $claire = $this->user('claire@client.test');

        $this->expectException(UserException::class);
        $this->commands->dispatch(new ResetTwoFactor((int) $claire->getId()));
    }

    public function testTheResetOfAnUnknownAccountIsRefused(): void
    {
        $this->expectException(UserException::class);
        $this->expectExceptionMessage('not found');
        $this->commands->dispatch(new ResetTwoFactor(0));
    }
}
