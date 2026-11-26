<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\TwoFactorCodes;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorCodeEvent;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A code is good once, wherever it is typed: the sign-in, the page that turns the second factor off and its set-up
 * share the memory of scheb.
 */
final class TwoFactorCodesTest extends KernelTestCase
{
    use TwoFactorHelpers;

    private TwoFactorCodes $codes;
    private User $claire;
    private string $secret;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('cache.app')->clear();
        self::awaitSafeWindow();
        $this->codes = self::getContainer()->get(TwoFactorCodes::class);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->claire = new User('claire@client.test', 'Claire', 'Test'));
        $this->secret = self::totpSecret();
        $this->enableTwoFactorOn($this->claire, $this->secret);
    }

    public function testACodeIsGoodOnce(): void
    {
        $code = self::totpCode($this->secret);

        self::assertTrue($this->codes->isValid($this->claire, $code));
        self::assertFalse($this->codes->isValid($this->claire, $code), 'the same code, even in its window, is not taken twice');
        self::assertFalse($this->codes->isValid($this->claire, '000000'));
    }

    public function testACodeThatSignedInIsNotTakenAgainByTheAccount(): void
    {
        $code = self::totpCode($this->secret);
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new TwoFactorCodeEvent($this->claire, $code), TwoFactorAuthenticationEvents::CODE_VALID);

        self::assertFalse($this->codes->isValid($this->claire, $code), 'someone who intercepted it cannot turn the second factor off with it');
    }

    public function testTheCodeOfTheScannedSecretIsGoodOnceToo(): void
    {
        $secret = self::totpSecret();
        $code = self::totpCode($secret);

        self::assertFalse($this->codes->isValidForSecret($this->claire, $secret, '000000'));
        self::assertTrue($this->codes->isValidForSecret($this->claire, $secret, $code));
        self::assertFalse($this->codes->isValidForSecret($this->claire, $secret, $code));
    }

    public function testASpacedCodeIsRead(): void
    {
        $code = self::totpCode($this->secret);

        self::assertTrue($this->codes->isValid($this->claire, substr($code, 0, 3).' '.substr($code, 3)));
    }
}
