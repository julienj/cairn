<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class UserTest extends TestCase
{
    use ClockSensitiveTrait;

    public function testAClientUserIsExternalWithTheUserRoleOnly(): void
    {
        self::mockTime('2026-03-03 10:00:00');
        $user = new User(' Claire@Example.com ', ' Claire ', ' Martin ');

        self::assertSame('claire@example.com', $user->getEmail());
        self::assertSame('claire@example.com', $user->getUserIdentifier());
        self::assertSame('Claire', $user->getFirstName());
        self::assertSame('Martin', $user->getLastName());
        self::assertSame('Claire Martin', $user->getFullName());
        self::assertFalse($user->isInternal());
        self::assertFalse($user->isAdmin());
        self::assertTrue($user->isActive());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertSame('2026-03-03 10:00:00', $user->getCreatedAt()->format('Y-m-d H:i:s'), 'created at the clock time');
    }

    public function testAnAccountSignsInWithAPasswordOrThroughTheProviderNeverBoth(): void
    {
        $user = new User('claire@example.com', 'Claire', 'Martin');
        self::assertFalse($user->usesSso());
        self::assertTrue($user->awaitsPassword(), 'invited, until it chooses a password');

        $user->setPassword('hashed');
        $user->signInWithSso();
        self::assertTrue($user->usesSso());
        self::assertFalse($user->hasPassword(), 'the password goes');
        self::assertFalse($user->awaitsPassword(), 'and none is expected');

        $user->bindSubject('acme-42');
        self::assertSame('acme-42', $user->getOidcSubject());

        $user->signInWithPassword();
        self::assertNull($user->getOidcSubject(), 'what the provider called it is forgotten');
        self::assertTrue($user->awaitsPassword());

        $this->expectException(\LogicException::class);
        $user->bindSubject('acme-42');
    }

    public function testAnAdministratorIsAnInternalUser(): void
    {
        $user = new User('thomas@agency.test', 'Thomas', 'Girard');
        $user->makeInternal(admin: true);

        self::assertTrue($user->isInternal());
        self::assertTrue($user->isAdmin());
        self::assertSame(['ROLE_USER', 'ROLE_INTERNAL', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testASecondFactorIsASecretThatCanBeTakenOff(): void
    {
        $user = new User('claire@example.com', 'Claire', 'Martin');
        self::assertFalse($user->isTotpAuthenticationEnabled());
        self::assertNull($user->getTotpAuthenticationConfiguration());

        $user->enableTotp('JBSWY3DPEHPK3PXP');

        self::assertTrue($user->isTotpAuthenticationEnabled());
        self::assertSame('claire@example.com', $user->getTotpAuthenticationUsername());
        self::assertSame('JBSWY3DPEHPK3PXP', $user->getTotpAuthenticationConfiguration()?->getSecret());

        $user->disableTotp();
        self::assertFalse($user->hasTotp());
    }

    public function testTheAccountOfTheProviderHoldsNoSecondFactor(): void
    {
        $user = new User('claire@example.com', 'Claire', 'Martin');
        $user->enableTotp('JBSWY3DPEHPK3PXP');

        $user->signInWithSso();

        self::assertFalse($user->hasTotp(), 'the provider has its own, the secret goes with the password');
        self::assertFalse($user->isTotpAuthenticationEnabled());
        $this->expectException(\LogicException::class);
        $user->enableTotp('JBSWY3DPEHPK3PXP');
    }
}
