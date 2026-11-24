<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Scene\ShopScene;
use App\Twig\SidebarExtension;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

final class SidebarTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $scene = ShopScene::load(self::getContainer());
        $this->client->loginUser($scene->user('claire.martin@maison-dupont.test'));
    }

    public function testTheProjectNavigationIsShownByDefault(): void
    {
        $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('html[data-sidebar]');
        self::assertSelectorExists('nav button[data-action="sidebar#toggle"][aria-expanded="true"][aria-label="Hide the navigation"]');
    }

    public function testTheProjectNavigationFoldedAwayStaysSoOnTheNextPages(): void
    {
        $this->client->getCookieJar()->set(new Cookie(SidebarExtension::COOKIE, 'collapsed'));

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[data-sidebar="collapsed"]');
        self::assertSelectorExists('nav button[data-action="sidebar#toggle"][aria-expanded="false"][aria-label="Show the navigation"]');
    }

    public function testAnUnknownValueShowsTheNavigation(): void
    {
        $this->client->getCookieJar()->set(new Cookie(SidebarExtension::COOKIE, 'folded'));

        $this->client->request('GET', '/MD/SPT/issues');

        self::assertSelectorNotExists('html[data-sidebar]');
    }
}
