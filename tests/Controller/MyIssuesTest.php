<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Team;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
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

    public function testTheIssuesOfMyTeamsAreAListOfTheirOwnForWhoeverIsPartOfATeam(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(Team::class, $team);
        $marc = $this->scene->user('marc.petit@cairn.test');
        $commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $marc->getId()));
        $commands->dispatch(new GrantTeamAccess((int) $team->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $commands->dispatch(new UpdateIssue((int) $this->scene->user('thomas.girard@cairn.test')->getId(), 'MD-SPT-1', assigneeTeamId: $team->getId()));

        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));
        $this->client->request('GET', '/issues/all');
        self::assertSelectorNotExists('main nav a[href="/issues/teams"]', 'part of no team');

        $this->client->loginUser($marc);
        $this->client->request('GET', '/issues/all');
        $this->client->clickLink('Assigned to my teams');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main nav [aria-current="page"]', 'Assigned to my teams');
        self::assertSelectorTextContains('tbody', 'Endless spinner on the cart page');
        self::assertSelectorCount(1, 'tbody tr[data-issue-key], tbody tr:has(a[href$="/issues/1"])');
        self::assertSelectorExists('tbody [title="Hosting"]', 'the team is drawn where the assignee is');

        $crawler = $this->client->request('GET', '/MD/SPT/issues?teams[]='.$team->getId());
        self::assertSelectorTextContains('tbody', 'Endless spinner on the cart page');
        self::assertCount(1, $crawler->filter('input[name="teams[]"][checked]'), 'the filter offers the teams of the project');
        self::assertSelectorTextNotContains('tbody', 'Typo in the footer');
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
