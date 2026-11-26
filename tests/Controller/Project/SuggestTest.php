<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Team;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the editor offers after `#` and `@`. In the scene MD-SPT-6 is an
 * internal task.
 */
final class SuggestTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testIssuesAreThoseTheUserMaySee(): void
    {
        self::assertNotContains('MD-SPT-6', $this->issueKeys('claire.martin@maison-dupont.test', '6'));
        self::assertContains('MD-SPT-6', $this->issueKeys('nadia.benali@cairn.test', '6'));
    }

    public function testANumberFindsTheIssuesItStarts(): void
    {
        $keys = $this->issueKeys('nadia.benali@cairn.test', '1');

        self::assertContains('MD-SPT-1', $keys);
        self::assertContains('MD-SPT-10', $keys);
        self::assertContains('MD-SPT-6', $keys, 'the title says "8.1"');
        self::assertNotContains('MD-SPT-2', $keys);
    }

    public function testPeopleAreTheMembersMatchingTheName(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/MD/SPT/suggest/users?q=nad');

        self::assertResponseIsSuccessful();
        $people = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['Nadia Benali'], array_column($people, 'name'));
    }

    public function testTheTeamsOfTheProjectAreOfferedBeforeThePeople(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $hosting = $commands->dispatch(new CreateTeam('Hosting', true));
        $commands->dispatch(new CreateTeam('Handling', true));
        self::assertInstanceOf(Team::class, $hosting);
        $commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/MD/SPT/suggest/users?q=h');

        self::assertResponseIsSuccessful();
        $found = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['id' => $hosting->getId(), 'name' => 'Hosting', 'team' => true, 'hint' => 'Team'], $found[0]);
        self::assertNotContains('Handling', array_column($found, 'name'), 'no role on the project');
        self::assertContains('Thomas Girard', array_column($found, 'name'), 'the people follow');
        self::assertArrayNotHasKey('team', $found[1]);
    }

    /**
     * @return list<string>
     */
    private function issueKeys(string $email, string $query): array
    {
        $this->signInAs($email);
        $this->client->request('GET', '/MD/SPT/suggest/issues?q='.$query);
        self::assertResponseIsSuccessful();

        return array_column(json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR), 'key');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}
