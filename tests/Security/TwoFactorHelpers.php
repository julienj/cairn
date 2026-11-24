<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\BackupCodes;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Clock\Clock;

use function Symfony\Component\Clock\now;

/**
 * What the tests of the second factor share: a secret and its current code (scheb reads the system clock, not
 * the one of the application), an account that holds both, and the instance told to require it, which is read
 * when the kernel boots: `requireTwoFactor()` comes before it in `setUp()`. The environment is put back as it was
 * when the test ends.
 *
 * @phpstan-require-extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
 */
trait TwoFactorHelpers
{
    /** @var list<string> */
    private const array BACKUP_CODES = ['1111-2222-3333-4444', 'aaaa-bbbb-cccc-dddd'];

    /** @var array<string, string|false> */
    private array $twoFactorEnvironmentBefore = [];

    private function requireTwoFactor(string $who): void
    {
        $this->twoFactorEnvironmentBefore['CAIRN_TWO_FACTOR'] ??= $_ENV['CAIRN_TWO_FACTOR'] ?? false;
        $_ENV['CAIRN_TWO_FACTOR'] = $_SERVER['CAIRN_TWO_FACTOR'] = $who;
    }

    #[After]
    protected function restoreTwoFactorEnvironment(): void
    {
        foreach ($this->twoFactorEnvironmentBefore as $name => $value) {
            if (false === $value) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        $this->twoFactorEnvironmentBefore = [];
    }

    private static function totpSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    private static function totpCode(string $secret): string
    {
        \assert('' !== $secret);

        return TOTP::createFromSecret($secret)->now();
    }

    /**
     * A code lives thirty seconds: one typed twice must not land on both sides of the change.
     */
    private static function awaitSafeWindow(): void
    {
        $left = 30 - (now()->getTimestamp() % 30);
        if ($left < 4) {
            Clock::get()->sleep($left + 1);
        }
    }

    private function enableTwoFactorOn(User $user, string $secret): void
    {
        $user->enableTotp($secret, BackupCodes::digests(self::BACKUP_CODES));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
