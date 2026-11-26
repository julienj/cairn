<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Factory\UserFactory;
use App\Repository\UserRepository;
use App\Tests\Security\TwoFactorHelpers;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UserResetTwoFactorCommandTest extends KernelTestCase
{
    use TwoFactorHelpers;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->tester = new CommandTester((new Application(self::bootKernel()))->find('app:user:reset-two-factor'));
    }

    public function testResetsTheSecondFactorOfAnAdministrator(): void
    {
        $julie = UserFactory::new()->admin()->create(['email' => 'julie@agency.test']);
        $this->enableTwoFactorOn($julie, self::totpSecret());

        $exitCode = $this->tester->execute(['email' => ' Julie@Agency.test ']);

        self::assertSame(Command::SUCCESS, $exitCode, $this->tester->getDisplay());
        self::assertFalse(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'julie@agency.test'])?->hasTotp());
    }

    public function testRefusesAnUnknownAccount(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'nobody@agency.test']));
        self::assertStringContainsString('No account', $this->tester->getDisplay());
    }

    public function testRefusesAnAccountWithoutOne(): void
    {
        UserFactory::createOne(['email' => 'claire@client.test']);

        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'claire@client.test']));
        self::assertStringContainsString('has no second factor', $this->tester->getDisplay());
    }
}
