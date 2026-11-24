<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MyIssuesTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testAllTheIssuesOfTheProjectsSeenWhoeverTheyAreAssignedTo(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        $crawler = $this->client->request('GET', '/issues/all');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main nav [aria-current="page"]', 'All');
        self::assertSelectorTextContains('tbody', 'Endless spinner on the cart page', 'unassigned');
        self::assertSelectorTextContains('tbody', 'Search by supplier reference', 'assigned to Léa');
        self::assertSelectorTextContains('tbody', 'Shipping costs are not shown for Corsica', 'assigned to her');
        self::assertCount(1, $crawler->filter('input[name="assignees[]"][value="0"]'), 'the unassigned ones can be filtered');
        self::assertCount(1, $crawler->filter('input[name="projects[]"]')->reduce(fn ($input): bool => (string) $this->scene->shop->getId() === $input->attr('value')));
    }

    public function testAnExternalUserSeesThereOnlyWhatTheyMaySee(): void
    {
        $this->client->loginUser($this->scene->user('claire.martin@maison-dupont.test'));

        $this->client->request('GET', '/issues/all');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', 'Endless spinner on the cart page');
        self::assertSelectorTextNotContains('tbody', 'Upgrade Symfony to 8.1', 'an internal task');
    }
}
