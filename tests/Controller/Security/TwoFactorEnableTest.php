<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Repository\UserRepository;
use App\Tests\Scene\ShopScene;
use App\Tests\Security\TwoFactorHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The page that sets the second factor up, in the layout of the sign-in: the QR code and the key of one secret,
 * and a code of the app that proves it works before anything is turned on.
 */
final class TwoFactorEnableTest extends WebTestCase
{
    use TwoFactorHelpers;

    private KernelBrowser $client;
    private User $claire;

    protected function setUp(): void
    {
        $this->requireTwoFactor('all');
        $this->client = self::createClient();
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
        $crawler = $this->client->request('GET', '/security/2fa/enable');
        self::assertResponseIsSuccessful();

        return str_replace(' ', '', trim($crawler->filter('[data-test="totp-secret"]')->text()));
    }

    public function testItShowsTheQrCodeAndTheKeyOfTheSameSecretUntilItIsConfirmed(): void
    {
        $crawler = $this->client->request('GET', '/security/2fa/enable');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('data:image/svg+xml', (string) $crawler->filter('img[data-test="totp-qr"]')->attr('src'));
        $secret = str_replace(' ', '', trim($crawler->filter('[data-test="totp-secret"]')->text()));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{16,}$/', $secret);
        self::assertSame($secret, $this->scannedSecret(), 'reloading the page does not change what was scanned');
        self::assertFalse($this->account()->hasTotp(), 'nothing is on until a code proves it works');
    }

    public function testItIsAStepOfTheSignInNotAPageOfTheAccount(): void
    {
        $this->client->request('GET', '/security/2fa/enable');

        self::assertSelectorNotExists('aside');
        self::assertSelectorTextContains('[role="alert"]', 'requires two-factor authentication');
        self::assertSelectorExists('a[href="/logout"]');
    }

    public function testTheCodeOfTheScannedSecretTurnsItOn(): void
    {
        $secret = $this->scannedSecret();

        $this->client->submitForm('Turn on', ['enable[code]' => self::totpCode($secret)]);

        self::assertResponseRedirects('/account/two-factor');
        $account = $this->account();
        self::assertTrue($account->hasTotp());
        self::assertSame($secret, $account->getTotpSecret());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Two-factor authentication is on.');
        self::assertResponseIsSuccessful('the session that set it up goes on, without being asked for the code again');
    }

    public function testItGoesBackToThePageThatWasAsked(): void
    {
        $this->client->request('GET', '/projects');
        self::assertResponseRedirects('/security/2fa/enable');
        $secret = $this->scannedSecret();

        $this->client->submitForm('Turn on', ['enable[code]' => self::totpCode($secret)]);

        self::assertResponseRedirects('http://localhost/projects');
    }

    public function testAFrameIsNotAPageToGoBackTo(): void
    {
        $this->client->request('GET', '/projects', server: ['HTTP_TURBO_FRAME' => 'modal']);
        self::assertResponseRedirects('/security/2fa/enable');
        $secret = $this->scannedSecret();

        $this->client->submitForm('Turn on', ['enable[code]' => self::totpCode($secret)]);

        self::assertResponseRedirects('/account/two-factor');
    }

    public function testAPictureIsNotAPageToGoBackTo(): void
    {
        $this->client->request('GET', '/projects');
        // What a page left open in another tab asks meanwhile.
        $this->client->request('GET', '/avatars/01234567-89ab-cdef-0123-456789abcdef.webp', server: ['HTTP_ACCEPT' => 'image/avif,image/webp,image/*,*/*;q=0.8']);
        self::assertResponseRedirects('/security/2fa/enable');
        $secret = $this->scannedSecret();

        $this->client->submitForm('Turn on', ['enable[code]' => self::totpCode($secret)]);

        self::assertResponseRedirects('http://localhost/projects');
    }

    public function testTheSecretShownToOneAccountIsNotOfferedToTheNext(): void
    {
        UserFactory::createOne(['email' => 'paul@client.test']);
        // A sign-in form left open in a tab, sent once Claire is on the page that sets it up.
        $this->client->request('GET', '/logout');
        $signIn = $this->client->request('GET', '/login')->selectButton('Sign in')->form(['email' => 'paul@client.test', 'password' => UserFactory::DEFAULT_PASSWORD]);
        $this->client->loginUser($this->claire);
        $secretOfClaire = $this->scannedSecret();

        $this->client->submit($signIn);

        self::assertNotSame($secretOfClaire, $this->scannedSecret(), 'Claire has seen that one');
    }

    public function testAWrongCodeTurnsNothingOnAndIsNotCountedAgainstThePerson(): void
    {
        $secret = $this->scannedSecret();

        for ($attempt = 0; $attempt < 7; ++$attempt) {
            $this->client->submitForm('Turn on', ['enable[code]' => '00000'.$attempt]);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('form', 'That code is incorrect.');
        }
        self::assertFalse($this->account()->hasTotp());

        $this->client->submitForm('Turn on', ['enable[code]' => self::totpCode($secret)]);
        self::assertResponseRedirects('/account/two-factor');
        self::assertTrue($this->account()->hasTotp(), 'the secret is on the screen: there is nothing to guess');
    }

    public function testTheCodeOfAnotherSecretDoesNotTurnItOn(): void
    {
        $this->scannedSecret();

        $this->client->submitForm('Turn on', ['enable[code]' => self::totpCode(self::totpSecret())]);

        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->account()->hasTotp());
    }

    public function testWhoHasItAlreadyIsSentToTheAccountPage(): void
    {
        $this->enableTwoFactorOn($this->claire, self::totpSecret());
        $this->client->loginUser($this->claire);

        $this->client->request('GET', '/security/2fa/enable');

        self::assertResponseRedirects('/account/two-factor');
    }

    public function testAnAccountOfTheIdentityProviderHasNothingToSetUpHere(): void
    {
        $this->client->loginUser(UserFactory::new()->internal()->sso()->create(['email' => 'lea@cairn.test']));

        $this->client->request('GET', '/security/2fa/enable');

        self::assertResponseRedirects('/account/two-factor');
    }

    public function testItIsForWhoIsSignedInOnly(): void
    {
        $this->client->request('GET', '/logout');

        $this->client->request('GET', '/security/2fa/enable');

        self::assertResponseRedirects('/login');
    }
}
