<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\WidgetSite;
use App\Entity\WidgetToken;
use App\Message\Command\Config\GrantPermission;
use App\Message\Command\Widget\AddWidgetSite;
use App\Message\CommandBus;
use App\Repository\WidgetSiteRepository;
use App\Repository\WidgetTokenRepository;
use App\Security\Permission;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The widget of the shop, in the configuration of the project: Thomas
 * manages Maison Dupont, Nadia develops on the shop, Claire is its client.
 */
final class WidgetSettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testThePageIsInTheConfigurationOfTheProjectForItsManagerOnly(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('a[href="/MD/SPT/settings/widget"]');
        $this->client->request('GET', '/MD/SPT/settings/widget');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No site yet');
        self::assertSelectorNotExists('[data-test^="widget-snippet"]', 'no site, no widget: nothing to install');

        $site = $this->site('https://staging.maison-dupont.test');
        foreach (['nadia.benali@cairn.test', 'claire.martin@maison-dupont.test'] as $email) {
            $this->signInAs($email);
            $this->client->request('GET', '/MD/SPT/issues');
            self::assertSelectorNotExists('a[href="/MD/SPT/settings/widget"]');
            foreach (['GET /MD/SPT/settings/widget', 'GET /MD/SPT/settings/widget/new', \sprintf('GET /MD/SPT/settings/widget/%d/remove', $site->getId()), \sprintf('POST /MD/SPT/settings/widget/%d/delete', $site->getId())] as $request) {
                $this->client->request(...explode(' ', $request));
                self::assertResponseStatusCodeSame(403, $email.' '.$request);
            }
        }
    }

    public function testThePermissionOfTheWidgetOpensItsPageAndNoOther(): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new GrantPermission('developer', Permission::WidgetManage));
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('aside a[href="/MD/SPT/settings/widget"]');
        self::assertSelectorNotExists('aside a[href="/MD/SPT/settings/general"]');
        $this->client->request('GET', '/MD/SPT/settings/widget');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/MD/SPT/settings/general');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheManagerDeclaresASiteByItsAddress(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/widget');
        $this->client->clickLink('New site');
        self::assertSelectorExists('turbo-frame#modal form[action="/MD/SPT/settings/widget/new"]');

        foreach (['staging.maison-dupont.test', 'ftp://staging.maison-dupont.test', 'https://staging.maison-dupont.test/shop'] as $address) {
            $this->client->submitForm('Add the site', ['widget_site[address]' => $address]);
            self::assertResponseStatusCodeSame(422, $address);
            self::assertSelectorTextContains('form[name="widget_site"]', 'not the address of a site');
        }

        $this->client->submitForm('Add the site', ['widget_site[address]' => ' HTTPS://Staging.Maison-Dupont.test:443/ ']);
        self::assertResponseRedirects('/MD/SPT/settings/widget');
        $sites = self::getContainer()->get(WidgetSiteRepository::class)->findByProject($this->scene->issue('MD-SPT-1')->getProject());
        self::assertSame(['https://staging.maison-dupont.test'], array_map(static fn (WidgetSite $site): string => $site->getOrigin(), $sites), 'only the origin is kept, as browsers write it');

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Site https://staging.maison-dupont.test added.');
        self::assertSelectorTextContains('[data-test="widget-snippet-always"]', '<script src="http://localhost/widget.js" data-project="MD-SPT" data-display="always" async></script>');
        self::assertSelectorTextContains('[data-test="widget-snippet-on_demand"]', '<script src="http://localhost/widget.js" data-project="MD-SPT" async></script>', 'without the attribute the button waits for ?cairn=1');
        self::assertSelectorNotExists('main li .ring-amber-line', 'HTTPS: the screenshot is offered');
        self::assertSelectorTextSame('[data-test="widget-link"]', 'https://staging.maison-dupont.test/?cairn=1', 'the address that asks for the widget in production, ready to send');
        self::assertSame(
            ['https://staging.maison-dupont.test/?cairn=1', 'https://staging.maison-dupont.test/?cairn=0'],
            $this->client->getCrawler()->filter('[data-clipboard-text-value]')->extract(['data-clipboard-text-value']),
            'and what its buttons copy: the address that shows it, the one that hides it',
        );

        $this->client->clickLink('New site');
        $this->client->submitForm('Add the site', ['widget_site[address]' => 'https://staging.maison-dupont.test']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="widget_site"]', 'already has the site');

        self::assertSame('https://xn--caf-dma.example.fr', WidgetSite::normalise('https://Café.example.fr/'), 'an accented domain is kept as the browsers send it');
    }

    public function testASiteWithoutHttpsIsSaidToHaveNoScreenshot(): void
    {
        $this->site('http://staging.maison-dupont.test');
        $this->site('http://staging.localhost:8001');
        $this->site('http://127.0.0.1:3000');
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/widget');

        self::assertSelectorCount(1, 'main li:contains("No screenshot")', 'a name or the loopback address of the machine is as good as HTTPS for the browsers');
        self::assertSelectorTextContains('main li:contains("No screenshot")', 'http://staging.maison-dupont.test');
    }

    public function testRemovingASiteRemovesTheTokensGivenForIt(): void
    {
        $site = $this->site('https://staging.maison-dupont.test');
        $other = $this->site('https://preprod.maison-dupont.test');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $claire = $this->scene->user('claire.martin@maison-dupont.test');
        $entityManager->persist(new WidgetToken($claire, $site, WidgetToken::newSecret()));
        $entityManager->persist(new WidgetToken($claire, $other, WidgetToken::newSecret()));
        $entityManager->flush();

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/widget');
        self::assertSelectorNotExists('form[action$="/delete"]', 'nothing on the page removes a site at once');
        // the bin of the site opens the dialog that asks again, in the frame of the page
        $this->client->click($this->client->getCrawler()->filter(\sprintf('a[data-turbo-frame="modal"][href="/MD/SPT/settings/widget/%d/remove"]', $site->getId()))->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="dialog"]', 'https://staging.maison-dupont.test');
        self::assertSelectorTextContains('[role="dialog"]', 'will have to link it again');
        $this->client->submitForm('Remove the site');
        self::assertResponseRedirects('/MD/SPT/settings/widget');

        $entityManager->clear();
        self::assertNull(self::getContainer()->get(WidgetSiteRepository::class)->find($site->getId()));
        $tokens = self::getContainer()->get(WidgetTokenRepository::class)->findAll();
        self::assertSame(['https://preprod.maison-dupont.test'], array_map(static fn (WidgetToken $token): string => $token->getSite()->getOrigin(), $tokens));
    }

    private function site(string $address): WidgetSite
    {
        $thomas = $this->scene->user('thomas.girard@cairn.test');
        $site = self::getContainer()->get(CommandBus::class)->dispatch(new AddWidgetSite((int) $thomas->getId(), (int) $this->scene->issue('MD-SPT-1')->getProject()->getId(), $address));
        \assert($site instanceof WidgetSite);

        return $site;
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}
