<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use App\Tests\Scene\ShopScene;
use App\Tests\Security\TwoFactorHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The page of the second factor of one's own account: set up with the QR code of a secret and a first code, the
 * backup codes shown once, new ones, turning it off, and the limit on the codes typed there.
 */
final class TwoFactorAccountTest extends WebTestCase
{
    use TwoFactorHelpers;

    private KernelBrowser $client;
    private User $claire;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
        self::awaitSafeWindow();
        $this->claire = ShopScene::load(self::getContainer())->user('claire.martin@maison-dupont.test');
        $this->client->loginUser($this->claire);
    }

    private function account(): User
    {
        $user = self::getContainer()->get(UserRepository::class)->find($this->claire->getId());
        self::assertNotNull($user);

        return $user;
    }

    /**
     * The secret the page shows, as typed in an app.
     */
    private function scannedSecret(): string
    {
        $crawler = $this->client->request('GET', '/account/two-factor');
        self::assertResponseIsSuccessful();

        return str_replace(' ', '', trim($crawler->filter('[data-test="totp-secret"]')->text()));
    }

    /**
     * @return list<string>
     */
    private function shownBackupCodes(): array
    {
        return array_values(array_map(trim(...), $this->client->getCrawler()->filter('[data-test="backup-codes"] span')->each(static fn ($span) => $span->text())));
    }

    private function turnOn(): string
    {
        $secret = $this->scannedSecret();
        $this->client->submitForm('Turn on', ['enable[code]' => self::totpCode($secret)]);
        self::assertResponseRedirects('/account/two-factor');
        $this->client->followRedirect();

        return $secret;
    }

    public function testTheAccountMenuLeadsToIt(): void
    {
        $this->client->request('GET', '/account');

        self::assertSelectorExists('aside a[href="/account/two-factor"]');
    }

    public function testTheSetupShowsTheQrCodeAndTheKeyOfTheSameSecretUntilItIsConfirmed(): void
    {
        $crawler = $this->client->request('GET', '/account/two-factor');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('data:image/svg+xml', (string) $crawler->filter('img[data-test="totp-qr"]')->attr('src'));
        $secret = str_replace(' ', '', trim($crawler->filter('[data-test="totp-secret"]')->text()));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{16,}$/', $secret);
        self::assertSame($secret, $this->scannedSecret(), 'reloading the page does not change what was scanned');
        self::assertFalse($this->account()->hasTotp(), 'nothing is on until a code proves it works');
    }

    public function testAWrongCodeLeavesItOff(): void
    {
        $this->scannedSecret();

        $this->client->submitForm('Turn on', ['enable[code]' => '000000']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'That code is incorrect.');
        self::assertFalse($this->account()->hasTotp());
    }

    public function testTheCodeOfTheScannedSecretTurnsItOnAndTheBackupCodesAreShownOnce(): void
    {
        $secret = $this->turnOn();

        $shown = $this->shownBackupCodes();
        self::assertCount(10, $shown);
        $account = $this->account();
        self::assertTrue($account->hasTotp());
        self::assertSame($secret, $account->getTotpSecret());
        self::assertSame(10, $account->countBackupCodes());
        foreach ($shown as $code) {
            self::assertTrue($account->isBackupCode($code), 'what is shown is what works');
        }
        self::assertSelectorTextContains('body', 'Two-factor authentication is on.');

        $this->client->request('GET', '/account/two-factor');
        self::assertSelectorNotExists('[data-test="backup-codes"]', 'the codes are not shown again');
        self::assertSelectorTextContains('body', '10 backup codes left.');
        self::assertSelectorNotExists('img[data-test="totp-qr"]');
    }

    public function testTheCodeOfAnotherSecretDoesNotTurnItOn(): void
    {
        $this->scannedSecret();

        $this->client->submitForm('Turn on', ['enable[code]' => self::totpCode(self::totpSecret())]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->account()->hasTotp());
    }

    public function testNewBackupCodesReplaceTheOnesThatWereDrawn(): void
    {
        $this->turnOn();
        $old = $this->shownBackupCodes();
        $secret = (string) $this->account()->getTotpSecret();

        $this->client->request('GET', '/account/two-factor');
        $this->client->submitForm('Draw new codes', ['regenerate[code]' => self::totpCode($secret)]);
        self::assertResponseRedirects('/account/two-factor');
        $this->client->followRedirect();

        $new = $this->shownBackupCodes();
        self::assertCount(10, $new);
        self::assertSame([], array_intersect($old, $new));
        $account = $this->account();
        self::assertFalse($account->isBackupCode($old[0]), 'the previous ones stop working');
        self::assertTrue($account->isBackupCode($new[0]));
    }

    public function testNewBackupCodesAreAskedWithACodeOnly(): void
    {
        $this->turnOn();
        $account = $this->account();
        $digest = $account->countBackupCodes();

        $this->client->request('GET', '/account/two-factor');
        $this->client->submitForm('Draw new codes', ['regenerate[code]' => '000000']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[action$="/two-factor/codes"]', 'That code is incorrect.');
        self::assertSame($digest, $this->account()->countBackupCodes());
    }

    public function testItIsTurnedOffWithACode(): void
    {
        $this->turnOn();
        $secret = (string) $this->account()->getTotpSecret();

        $this->client->request('GET', '/account/two-factor');
        $this->client->submitForm('Turn off', ['disable[code]' => '000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->account()->hasTotp(), 'a wrong code turns nothing off');

        $this->client->submitForm('Turn off', ['disable[code]' => self::totpCode($secret)]);
        self::assertResponseRedirects('/account/two-factor');
        $this->client->followRedirect();

        self::assertFalse($this->account()->hasTotp());
        self::assertSame(0, $this->account()->countBackupCodes());
        self::assertSelectorTextContains('body', 'Two-factor authentication is off.');
    }

    public function testTheCodesAreTriedAtAFewTimesOnly(): void
    {
        $this->turnOn();
        $secret = (string) $this->account()->getTotpSecret();
        $this->client->request('GET', '/account/two-factor');

        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->client->submitForm('Turn off', ['disable[code]' => '00000'.$attempt]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->client->submitForm('Turn off', ['disable[code]' => self::totpCode($secret)]);

        self::assertResponseRedirects('/account/two-factor');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Too many attempts.');
        self::assertTrue($this->account()->hasTotp(), 'even the right code waits');
    }

    public function testTheManagementActionsLeadBackWhenThereIsNothingToManage(): void
    {
        $this->client->request('POST', '/account/two-factor/disable');
        self::assertResponseRedirects('/account/two-factor');

        $this->client->request('POST', '/account/two-factor/codes');
        self::assertResponseRedirects('/account/two-factor');
    }

    public function testAnAccountOfTheIdentityProviderIsToldItHasItsOwn(): void
    {
        $this->client->loginUser(UserFactory::new()->internal()->sso()->create(['email' => 'lea@cairn.test']));

        $this->client->request('GET', '/account/two-factor');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'managed at');
        self::assertSelectorNotExists('img[data-test="totp-qr"]');
    }

    public function testThePasswordChangeKeepsTheSessionWithoutAskingForTheCodeAgain(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->claire->setPassword($hasher->hashPassword($this->claire, 'the old password'));
        $this->enableTwoFactorOn($this->claire, self::totpSecret());
        $this->client->loginUser($this->claire);

        $crawler = $this->client->request('GET', '/account/security');
        $this->client->submit($crawler->selectButton('Change password')->form([
            'password_change[currentPassword]' => 'the old password',
            'password_change[newPassword][first]' => 'a new password',
            'password_change[newPassword][second]' => 'a new password',
        ]));
        self::assertResponseRedirects('/account/security');

        $this->client->request('GET', '/account/security');
        self::assertResponseIsSuccessful('the person already went through the second factor on this session');
        self::assertTrue($hasher->isPasswordValid($this->account(), 'a new password'));
    }
}
