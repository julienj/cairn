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
 * The page of the second factor of one's own account: where it stands, the way to set it up, turning it off, and
 * the limit on the codes typed there.
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
     * Gives the account a second factor and signs it in again, as the page of a person who just set it up.
     */
    private function turnedOn(): string
    {
        $secret = self::totpSecret();
        $this->enableTwoFactorOn($this->claire, $secret);
        $this->client->loginUser($this->claire);

        return $secret;
    }

    public function testTheAccountMenuLeadsToIt(): void
    {
        $this->client->request('GET', '/account');

        self::assertSelectorExists('aside a[href="/account/two-factor"]');
    }

    public function testItOffersToSetItUpWhenThereIsNone(): void
    {
        $this->client->request('GET', '/account/two-factor');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/security/2fa/enable"]');
        self::assertSelectorNotExists('img[data-test="totp-qr"]', 'the page that sets it up is the sign-in\'s');
    }

    public function testASessionOpenedBeforeItWasTurnedOnIsClosed(): void
    {
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();

        $this->enableTwoFactorOn($this->claire, self::totpSecret());

        $this->client->request('GET', '/account');
        self::assertResponseRedirects('http://localhost/login', null, 'someone who fears a leaked password turns it on: the session of the thief ends');
    }

    public function testItShowsItIsOnWhenThereIsOne(): void
    {
        $this->turnedOn();

        $this->client->request('GET', '/account/two-factor');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="/security/2fa/enable"]');
        self::assertSelectorExists('form[action$="/two-factor/disable"]');
    }

    public function testItIsTurnedOffWithACode(): void
    {
        $secret = $this->turnedOn();

        $this->client->request('GET', '/account/two-factor');
        $this->client->submitForm('Turn off', ['disable[code]' => '000000']);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->account()->hasTotp(), 'a wrong code turns nothing off');

        $this->client->submitForm('Turn off', ['disable[code]' => self::totpCode($secret)]);
        self::assertResponseRedirects('/account/two-factor');
        $this->client->followRedirect();

        self::assertFalse($this->account()->hasTotp());
        self::assertSelectorTextContains('body', 'Two-factor authentication is off.');

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful('the session that turned it off goes on, without a code');
    }

    public function testTheCodesAreTriedAtAFewTimesOnly(): void
    {
        $secret = $this->turnedOn();
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

    public function testTurningItOffLeadsBackWhenThereIsNothingToTurnOff(): void
    {
        $this->client->request('POST', '/account/two-factor/disable');
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
