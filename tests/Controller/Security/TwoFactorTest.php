<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Tests\KernelHelpers;
use App\Tests\Security\TwoFactorHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The sign-in of an account that holds a second factor: the password opens nothing by itself, a code of the app
 * or a backup code finishes it, the codes are guessed at a few tries only, and "remember me" is given once the
 * code was typed.
 */
final class TwoFactorTest extends WebTestCase
{
    use KernelHelpers;
    use TwoFactorHelpers;

    private KernelBrowser $client;
    private string $secret;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::applyConfiguration();
        self::getContainer()->get('cache.rate_limiter')->clear();
        self::getContainer()->get('cache.app')->clear();
        self::awaitSafeWindow();

        $this->secret = self::totpSecret();
        $claire = UserFactory::createOne(['email' => 'claire@client.test', 'firstName' => 'Claire', 'lastName' => 'Martin']);
        $this->enableTwoFactorOn($claire, $this->secret);
    }

    public function testThePasswordAloneOpensNothing(): void
    {
        $this->signIn();

        // The password leads where it always did; the next request is sent to the code.
        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertResponseRedirects('/2fa');
        foreach (['/', '/account', '/projects'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseRedirects('/2fa', null, $url);
        }
        $crawler = $this->client->request('GET', '/2fa');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Two-factor authentication');
        self::assertCount(1, $crawler->filter('input[name="_auth_code"][autocomplete="one-time-code"]'));
    }

    public function testTheCodeOfTheAppFinishesTheSignIn(): void
    {
        $this->signIn();

        $this->submitCode(self::totpCode($this->secret));

        self::assertResponseRedirects('/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Hello Claire');
    }

    public function testAWrongCodeIsRefusedAndTheSessionStaysClosed(): void
    {
        $this->signIn();

        $this->submitCode('000000');

        self::assertResponseRedirects('/2fa');
        $this->client->followRedirect();
        self::assertSelectorExists('[role="alert"]');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa');
    }

    public function testTheCodeIsAskedOfWhoHasTypedThePasswordOnly(): void
    {
        // Nobody opens the check path without the password: a code is worth nothing alone.
        $this->client->request('POST', '/2fa_check', ['_auth_code' => self::totpCode($this->secret)]);

        self::assertResponseRedirects('/login');
    }

    public function testABackupCodeFinishesTheSignInOnceAndWhateverHowItIsTyped(): void
    {
        $this->signIn();
        $this->submitCode('1111 2222-3333 4444');
        self::assertResponseRedirects('/');
        self::assertSame(1, $this->claire()->countBackupCodes(), 'the code is spent');

        $this->client->request('GET', '/logout');
        $this->signIn();
        $this->submitCode('1111-2222-3333-4444');

        self::assertResponseRedirects('/2fa');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa', null, 'a spent code opens nothing');
        self::assertSame(1, $this->claire()->countBackupCodes());
    }

    public function testACodeIsGoodOnce(): void
    {
        $code = self::totpCode($this->secret);
        $this->signIn();
        $this->submitCode($code);
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/logout');
        $this->signIn();
        $this->submitCode($code);

        self::assertResponseRedirects('/2fa', null, 'the same code, even in its window, is not taken twice');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa');
    }

    public function testTheCodesAreGuessedAtAFewTriesOnly(): void
    {
        $this->signIn();
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $this->submitCode('00000'.$attempt);
        }

        $this->submitCode(self::totpCode($this->secret));

        self::assertResponseRedirects('/2fa');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Too many attempts');
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/2fa', null, 'even the right code waits');
    }

    public function testARightCodeForgetsTheWrongOnes(): void
    {
        $this->signIn();
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->submitCode('00000'.$attempt);
        }
        $this->submitCode(self::totpCode($this->secret));
        self::assertResponseRedirects('/');

        $this->client->request('GET', '/logout');
        $this->signIn();
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $this->submitCode('00001'.$attempt);
        }
        self::assertResponseRedirects('/2fa');
        $this->client->followRedirect();
        self::assertSelectorNotExists('[role="alert"]:contains("Too many")', 'the count started again after the right code');
    }

    public function testRememberMeIsGivenOnceTheCodeWasTyped(): void
    {
        $this->signIn(rememberMe: true);
        $this->client->followRedirect();
        self::assertResponseRedirects('/2fa');
        self::assertBrowserNotHasCookie('REMEMBERME', message: 'the password alone does not earn it');

        $this->submitCode(self::totpCode($this->secret));

        self::assertResponseRedirects('/');
        self::assertBrowserHasCookie('REMEMBERME');
    }

    public function testAnAccountWithoutASecondFactorSignsInWithItsPasswordOnly(): void
    {
        UserFactory::createOne(['email' => 'paul@client.test']);

        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form(['email' => 'paul@client.test', 'password' => UserFactory::DEFAULT_PASSWORD]));

        self::assertResponseRedirects('/');
    }

    private function signIn(bool $rememberMe = false): void
    {
        $crawler = $this->client->request('GET', '/login');
        $values = ['email' => 'claire@client.test', 'password' => UserFactory::DEFAULT_PASSWORD];
        if ($rememberMe) {
            $values['_remember_me'] = '1';
        }
        $this->client->submit($crawler->selectButton('Sign in')->form($values));
    }

    private function submitCode(string $code): void
    {
        $crawler = $this->client->request('GET', '/2fa');
        $this->client->submit($crawler->selectButton('Verify')->form(['_auth_code' => $code]));
    }

    private function claire(): User
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'claire@client.test']) ?? self::fail('no account');
    }
}
