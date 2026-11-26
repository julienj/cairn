<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Team;
use App\Entity\Tracker;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Repository\TrackerRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The configuration and the access of a project, for its manager: Thomas
 * manages Maison Dupont, Nadia develops on the shop, Claire is its client.
 * The ordering of trackers is covered by OrderTest, the grants by GrantsTest.
 */
final class SettingsTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheManagerFindsTheConfigurationAndTheAccessInTheNavigation(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('a[href="/MD/SPT/settings"]');
        self::assertSelectorExists('a[href="/MD/SPT/access"]');

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('a[href="/MD/SPT/settings"]');
        self::assertSelectorNotExists('a[href="/MD/SPT/access"]');
    }

    public function testWhoDoesNotManageTheProjectIsRefused(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        foreach (['/MD/SPT/settings', '/MD/SPT/settings/general', '/MD/SPT/access'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testTheAccessPageListsPeopleThenTheTeams(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam('Hosting', true));
        \assert($team instanceof Team);
        $commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $this->scene->user('marc.petit@cairn.test')->getId()));
        $commands->dispatch(new GrantTeamAccess((int) $team->getId(), null, $this->scene->shop->getId(), 'developer'));

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/access');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Nadia Benali');
        self::assertSelectorTextContains('main', 'Teams');
        self::assertSelectorTextContains('main', 'Hosting');
        self::assertSelectorTextContains('main', 'Marc Petit');
    }

    public function testTheManagerCreatesATracker(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings');
        self::assertResponseRedirects(null, null, 'the page of the first tracker, the others beside it');
        $this->client->followRedirect();
        self::assertRouteSame('app_project_settings_tracker');
        self::assertSelectorTextContains('main', 'Internal task');
        self::assertSelectorNotExists('[role="dialog"]');
        self::assertSelectorExists('turbo-frame#modal', 'where the dialog loads over the page');

        $this->client->clickLink('New category');
        self::assertRouteSame('app_project_settings_tracker_new');
        self::assertSelectorExists('turbo-frame#modal form[name="new_tracker"][action="/MD/SPT/settings/trackers/new"] [role="dialog"]', 'loaded over the page, the form is still sent to the dialog, under a name of its own');

        $this->client->submitForm('Create the category', ['new_tracker[name]' => ''], 'POST');
        self::assertResponseStatusCodeSame(422);
        $this->client->submitForm('Create the category', ['new_tracker[name]' => '   ', 'new_tracker[workflowId]' => 'task'], 'POST');
        self::assertResponseStatusCodeSame(422, 'a blank name is refused by the form, not by the bus');

        $this->client->submitForm('Create the category', [
            'new_tracker[name]' => 'Support',
            'new_tracker[description]' => 'A question about the site.',
            'new_tracker[workflowId]' => 'task',
        ]);

        $support = self::getContainer()->get(TrackerRepository::class)->findOneBy(['name' => 'Support']);
        self::assertInstanceOf(Tracker::class, $support);
        self::assertSame('task', $support->getWorkflowId());
        self::assertSame(3, $support->getPosition());
        self::assertResponseRedirects(\sprintf('/MD/SPT/settings/trackers/%d', $support->getId()));
    }

    public function testTheDialogCreatingATrackerIsAnsweredAloneToItsFrame(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $frame = ['HTTP_TURBO_FRAME' => 'modal'];

        $this->client->request('GET', '/MD/SPT/settings/trackers/new', [], [], $frame);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal form[name="new_tracker"]');
        self::assertSelectorNotExists('nav', 'nothing of the page behind the dialog');

        $this->client->submitForm('Create the category', ['new_tracker[name]' => ''], 'POST', $frame);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#modal [role="dialog"]', 'refused, the dialog alone is drawn again');
        self::assertSelectorNotExists('nav');

        $this->client->request('GET', \sprintf('/MD/SPT/settings/trackers/%d', $this->scene->evolutions->getId()), [], [], $frame);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('turbo-frame#modal *', 'where a creation leads, the frame is answered empty');
        self::assertSelectorNotExists('nav');
    }

    public function testTheManagerArchivesATrackerWhoseWorkflowIsShown(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $url = \sprintf('/MD/SPT/settings/trackers/%d', $this->scene->evolutions->getId());

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-controller="workflow-diagram"]');

        $this->client->submitForm('Save', ['tracker[name]' => 'Features']);
        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'by clients too', 'what archiving means is said under the button');
        $this->client->submitForm('Archive');
        self::assertResponseRedirects($url);
        $this->client->followRedirect();
        self::assertSelectorTextContains(\sprintf('#tracker-%d', $this->scene->evolutions->getId()), 'Archived');
        self::assertSelectorExists('form[action$="/unarchive"]');
        self::assertSelectorNotExists('form[action$="/delete"]', 'a tracker that holds issues is not deleted');

        $evolutions = self::getContainer()->get(TrackerRepository::class)->find($this->scene->evolutions->getId());
        self::assertInstanceOf(Tracker::class, $evolutions);
        self::assertSame('Features', $evolutions->getName());
        self::assertTrue($evolutions->isArchived());

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists(\sprintf('nav a[href="/MD/SPT/issues/tracker/%d"]', $evolutions->getId()), 'an archived tracker stays in the menu, as an archived project stays listed');

        $this->client->request('GET', '/MD/SPT/issues/new');
        self::assertSelectorTextNotContains('main', 'Features');
    }

    public function testTheManagerDeletesATrackerWithoutIssues(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/trackers/new');
        $this->client->submitForm('Create the category', ['new_tracker[name]' => 'Support', 'new_tracker[workflowId]' => 'bug']);
        $crawler = $this->client->followRedirect();
        $url = $this->client->getRequest()->getPathInfo();

        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/MD/SPT/settings');
        $this->client->followRedirect();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Category "Support" deleted.');

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function testATrackerOfAnotherProjectIsNotFound(): void
    {
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', \sprintf('/MD/INT/settings/trackers/%d', $this->scene->bugs->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheManagerRenamesTheProjectAndDescribesIt(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/settings/general');

        $this->client->submitForm('Save', [
            'project_settings[name]' => 'Web shop',
            'project_settings[description]' => 'The public site and the back office.',
        ]);

        self::assertResponseRedirects('/MD/SPT/settings/general');
        $this->client->followRedirect();
        self::assertInputValueSame('project_settings[name]', 'Web shop');
        self::assertSelectorTextContains('textarea', 'The public site and the back office.');
    }

    public function testTheAccessPageShowsTheReferentsTheInheritedRolesThenTheDirectOnes(): void
    {
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', '/MD/SPT/access');

        self::assertResponseIsSuccessful();
        $sections = $this->client->getCrawler()->filter('main section');
        self::assertStringContainsString('Referents', $sections->eq(0)->text(), 'who answers for each role comes first');
        self::assertStringContainsString('Claire Martin', $sections->eq(1)->text(), 'inherited from Maison Dupont');
        self::assertStringContainsString('Nadia Benali', $sections->eq(2)->text(), 'held on the shop');
        self::assertStringNotContainsString('Claire Martin', $sections->eq(2)->filter('ul')->text());
        self::assertSelectorNotExists('[role="dialog"]');

        $this->client->clickLink('Add someone');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[role="dialog"]', 'Add someone to Online shop');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}
