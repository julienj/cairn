<?php

declare(strict_types=1);

namespace App\Tests\Controller\Security;

use App\Entity\User;
use App\Factory\UserFactory;
use App\Security\TwoFactorPolicy;
use App\Tests\Scene\ShopScene;
use App\Tests\Security\TwoFactorHelpers;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the instance holds its accounts to (`CAIRN_TWO_FACTOR`): the ones that must and have no second factor yet
 * can do nothing but set it up, and the accounts of the identity provider are never asked. Thomas is internal,
 * Claire external.
 */
final class TwoFactorEnrolmentTest extends WebTestCase
{
    use TwoFactorHelpers;

    private KernelBrowser $client;

    private function boot(string $who): void
    {
        $this->requireTwoFactor($who);
        $this->client = self::createClient();
    }

    public function testNobodyIsHeldToItByDefault(): void
    {
        $this->boot('optional');
        $scene = ShopScene::load(self::getContainer());

        foreach (['thomas.girard@cairn.test', 'claire.martin@maison-dupont.test'] as $email) {
            $this->client->loginUser($scene->user($email));
            $this->client->request('GET', '/account');
            self::assertResponseIsSuccessful($email);
        }
    }

    public function testEverybodyMustWhenTheInstanceSaysSo(): void
    {
        $this->boot('all');
        $scene = ShopScene::load(self::getContainer());

        foreach (['thomas.girard@cairn.test', 'claire.martin@maison-dupont.test'] as $email) {
            $this->client->loginUser($scene->user($email));
            foreach (['/', '/account', '/account/notifications', '/projects', '/MD/SPT/issues'] as $url) {
                $this->client->request('GET', $url);
                self::assertResponseRedirects('/account/two-factor', null, $email.' '.$url);
            }
        }
    }

    public function testOnlyTheInternalUsersMustWhenTheInstanceSaysSo(): void
    {
        $this->boot('internal');
        $scene = ShopScene::load(self::getContainer());

        $this->client->loginUser($scene->user('thomas.girard@cairn.test'));
        $this->client->request('GET', '/account');
        self::assertResponseRedirects('/account/two-factor');

        $this->client->loginUser($scene->user('claire.martin@maison-dupont.test'));
        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful('an external user chooses');
    }

    public function testWhoMustCanSetItUpAndSignOutAndNothingElse(): void
    {
        $this->boot('all');
        $this->client->loginUser(ShopScene::load(self::getContainer())->user('thomas.girard@cairn.test'));

        $this->client->request('GET', '/account/two-factor');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="alert"]', 'requires two-factor authentication');
        self::assertSelectorExists('img[data-test="totp-qr"]');

        $this->client->request('GET', '/logout');
        self::assertResponseRedirects('/login');
    }

    public function testWhoHasSetItUpIsLetThrough(): void
    {
        $this->boot('all');
        $thomas = ShopScene::load(self::getContainer())->user('thomas.girard@cairn.test');
        $this->enableTwoFactorOn($thomas, self::totpSecret());
        $this->client->loginUser($thomas);

        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
    }

    public function testWhoIsHeldToItCannotTurnItOff(): void
    {
        $this->boot('all');
        $thomas = ShopScene::load(self::getContainer())->user('thomas.girard@cairn.test');
        $this->enableTwoFactorOn($thomas, self::totpSecret());
        $this->client->loginUser($thomas);

        $this->client->request('GET', '/account/two-factor');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'it cannot be turned off');
        self::assertSelectorNotExists('form[action$="/two-factor/disable"]');
    }

    public function testAnAccountOfTheIdentityProviderIsNeverHeldToIt(): void
    {
        $this->boot('all');
        $sso = UserFactory::new()->internal()->sso()->create(['email' => 'lea@cairn.test']);
        $this->client->loginUser($sso);

        $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful('the provider has its own second factor');
        self::assertInstanceOf(User::class, $sso);
    }

    public function testTheValueOfTheVariableIsChecked(): void
    {
        $this->boot('everybody');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CAIRN_TWO_FACTOR is "everybody"');
        self::getContainer()->get(TwoFactorPolicy::class);
    }
}
