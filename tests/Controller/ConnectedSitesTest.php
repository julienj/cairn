<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\WidgetSite;
use App\Entity\WidgetToken;
use App\Message\Command\Widget\AddWidgetSite;
use App\Message\CommandBus;
use App\Repository\WidgetTokenRepository;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * The sites an account is linked on through the widget, on its own
 * account: Claire is the client of the shop, which is reviewed on two
 * sites; Thomas manages it.
 */
final class ConnectedSitesTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string CLAIRE = 'claire.martin@maison-dupont.test';
    private const string THOMAS = 'thomas.girard@cairn.test';

    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testThePageShowsInTheAccountOnceASiteIsConnected(): void
    {
        $this->client->loginUser($this->scene->user(self::CLAIRE));
        $this->client->request('GET', '/account');
        self::assertSelectorNotExists('aside a[href="/account/sites"]', 'nothing connected, nothing to show');
        $this->client->request('GET', '/account/sites');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No connected site');

        $this->token(self::CLAIRE, 'https://staging.maison-dupont.test');
        $this->token(self::THOMAS, 'https://preprod.maison-dupont.test');

        $this->client->request('GET', '/account');
        self::assertSelectorExists('aside a[href="/account/sites"]');
        $this->client->request('GET', '/account/sites');
        self::assertSelectorCount(1, '[data-test="connected-site"]', 'one\'s own, not those of the others');
        self::assertSelectorTextContains('[data-test="connected-site"]', 'staging.maison-dupont.test');
        self::assertSelectorTextNotContains('[data-test="connected-site"]', 'https://');
        self::assertSelectorTextContains('[data-test="connected-site"]', $this->scene->issue('MD-SPT-1')->getProject()->getName());

        static::mockTime('+31 days');
        $this->client->request('GET', '/account/sites');
        self::assertSelectorNotExists('[data-test="connected-site"]', 'a connection that ended is not one');
        $this->client->request('GET', '/account');
        self::assertSelectorNotExists('aside a[href="/account/sites"]');
    }

    public function testASiteIsDisconnectedByItsAccountOnly(): void
    {
        $mine = $this->token(self::CLAIRE, 'https://staging.maison-dupont.test');
        $other = $this->token(self::THOMAS, 'https://staging.maison-dupont.test');

        $this->client->loginUser($this->scene->user(self::CLAIRE));
        $form = $this->client->request('GET', '/account/sites')->selectButton('Disconnect')->form();
        $this->client->request('POST', \sprintf('/account/sites/%d/disconnect', $other->getId()), $form->getPhpValues());
        self::assertResponseStatusCodeSame(404, 'the connection of someone else does not exist');

        $this->client->request('GET', '/account/sites');
        $this->client->submitForm('Disconnect');
        self::assertResponseRedirects('/account/sites');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Site staging.maison-dupont.test disconnected.');
        self::assertSelectorNotExists('[data-test="connected-site"]');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $left = self::getContainer()->get(WidgetTokenRepository::class)->findAll();
        self::assertSame([$other->getId()], array_map(static fn (WidgetToken $token): ?int => $token->getId(), $left));
        self::assertNotSame($mine->getId(), $other->getId());
    }

    private function token(string $email, string $address): WidgetToken
    {
        // as the kernel of the moment holds them: each request boots another one
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $project = $entityManager->getRepository(Project::class)->find($this->scene->issue('MD-SPT-1')->getProject()->getId());
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($project instanceof Project && $user instanceof User);
        $site = $entityManager->getRepository(WidgetSite::class)->findOneBy(['project' => $project, 'origin' => $address])
            ?? self::getContainer()->get(CommandBus::class)->dispatch(new AddWidgetSite((int) $this->scene->user(self::THOMAS)->getId(), (int) $project->getId(), $address));
        \assert($site instanceof WidgetSite);
        $token = new WidgetToken($user, $site, WidgetToken::newSecret());
        $entityManager->persist($token);
        $entityManager->flush();

        return $token;
    }
}
