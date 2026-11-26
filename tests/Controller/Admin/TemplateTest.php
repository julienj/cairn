<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\AutomationRule;
use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Entity\Team;
use App\Entity\TemplateRule;
use App\Entity\TemplateTracker;
use App\Entity\Tracker;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Template\CreateTemplate;
use App\Message\Command\Template\CreateTemplateRule;
use App\Message\Command\Template\GrantTemplateTeamAccess;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The project templates of the administration, and the project created
 * with one. Julie administers the instance, Thomas manages a project.
 */
final class TemplateTest extends WebTestCase
{
    private const array THANKS = ['trigger' => ['type' => 'issue_created'], 'actions' => [['type' => 'comment', 'params' => ['body' => 'Thank you.']]]];

    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testOnlyAdministratorsEnter(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        $this->client->request('GET', '/admin/templates');
        self::assertResponseStatusCodeSame(403);
    }

    public function testATemplateIsComposedThenGivenToANewProject(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));

        $this->client->request('GET', '/admin/projects/new');
        self::assertSelectorNotExists('[name="project[template]"]', 'no template, nothing to choose');

        $this->client->request('GET', '/admin/templates');
        self::assertSelectorTextContains('main', 'No template yet');
        $this->client->clickLink('New template');
        $this->client->submitForm('Create', ['template[name]' => 'Web project', 'template[description]' => 'For the sites we build.']);
        $template = $this->entity(ProjectTemplate::class, ['name' => 'Web project']) ?? self::fail('No template was created.');
        $base = '/admin/templates/'.$template->getId();
        self::assertResponseRedirects($base);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'No category yet');

        $this->client->request('GET', '/admin/templates/new');
        $this->client->submitForm('Create', ['template[name]' => 'Web project']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'A template is already named Web project.');

        $this->client->request('GET', $base.'/trackers/new');
        $this->client->submitForm('Create', ['tracker[name]' => 'Bug', 'tracker[workflowId]' => 'bug']);
        $bugs = $this->entity(TemplateTracker::class, ['name' => 'Bug']) ?? self::fail('No tracker was created.');
        self::assertResponseRedirects($base.'/trackers/'.$bugs->getId());
        $this->client->followRedirect();
        $this->client->submitForm('Save', ['tracker[name]' => 'Defect', 'tracker[private]' => true]);
        self::assertResponseRedirects($base.'/trackers/'.$bugs->getId());
        self::assertTrue($this->entity(TemplateTracker::class, ['name' => 'Defect'])?->isPrivate());

        $rule = self::getContainer()->get(CommandBus::class)->dispatch(new CreateTemplateRule((int) $template->getId(), 'New issue to the manager', null, [
            'trigger' => ['type' => 'issue_created'],
            'actions' => [['type' => 'assign', 'params' => ['target' => 'referent:project_manager']]],
        ]));
        $this->client->request('GET', $base.'/automations');
        self::assertSelectorTextContains('main', 'When an issue is created: assign it to the Project manager referent.');
        $this->client->request('GET', $base.'/automations/'.$rule->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[value="New issue to the manager"]');
        $this->client->request('GET', $base.'/automations/new');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/templates');
        self::assertSelectorTextContains('main', 'For the sites we build.');
        self::assertSame(['1', '1'], $this->client->getCrawler()->filter('main tbody td')->slice(1)->each(static fn ($cell): string => trim($cell->text())), 'one tracker, one rule');

        $this->client->request('GET', '/admin/projects/new?workspace=MD');
        $this->client->submitForm('Create', ['project[name]' => 'Application', 'project[key]' => 'app', 'project[template]' => (string) $template->getId()]);
        self::assertResponseRedirects('/admin/workspaces/MD');
        $project = $this->entity(Project::class, ['key' => 'APP']) ?? self::fail('No project was created.');
        self::assertSame('Defect', $this->entity(Tracker::class, ['project' => $project])?->getName());
        self::assertSame('New issue to the manager', $this->entity(AutomationRule::class, ['project' => $project])?->getName());

        $this->client->request('GET', $base.'/settings');
        $this->client->submitForm('Delete the template');
        self::assertResponseRedirects('/admin/templates');
        self::assertNull($this->entity(ProjectTemplate::class, ['name' => 'Web project']));
        self::assertNotNull($this->entity(Tracker::class, ['project' => $project]), 'the project keeps what it was given');
    }

    public function testTheAccessTabOfATemplateListsTheRolesItGivesToTeams(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $template = $commands->dispatch(new CreateTemplate('Web project'));
        $hosting = $commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(ProjectTemplate::class, $template);
        self::assertInstanceOf(Team::class, $hosting);
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));

        $this->client->request('GET', '/admin/templates/'.$template->getId());
        $this->client->clickLink('Access');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'This template gives no team a role.');

        $commands->dispatch(new GrantTemplateTeamAccess((int) $template->getId(), (int) $hosting->getId(), 'developer'));
        $this->client->request('GET', '/admin/templates/'.$template->getId().'/access');
        self::assertSelectorTextContains('main ul', 'Hosting');
    }

    public function testATrackerOrARuleOfAnotherTemplateIsNotFound(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
        foreach (['One', 'Two'] as $name) {
            $this->client->request('GET', '/admin/templates/new');
            $this->client->submitForm('Create', ['template[name]' => $name]);
        }
        $one = $this->entity(ProjectTemplate::class, ['name' => 'One']) ?? self::fail('No template One.');
        $two = $this->entity(ProjectTemplate::class, ['name' => 'Two']) ?? self::fail('No template Two.');
        $this->client->request('GET', '/admin/templates/'.$one->getId().'/trackers/new');
        $this->client->submitForm('Create', ['tracker[name]' => 'Bug', 'tracker[workflowId]' => 'bug']);
        $tracker = $this->entity(TemplateTracker::class, ['name' => 'Bug']) ?? self::fail('No tracker was created.');

        $rule = self::getContainer()->get(CommandBus::class)->dispatch(new CreateTemplateRule((int) $one->getId(), 'Thanks', null, self::THANKS));
        self::assertInstanceOf(TemplateRule::class, $rule);

        foreach (['/trackers/'.$tracker->getId(), '/automations/'.$rule->getId()] as $path) {
            $this->client->request('GET', '/admin/templates/'.$two->getId().$path);
            self::assertResponseStatusCodeSame(404, $path);
        }
    }

    public function testARuleIsSwitchedOffAndATrackerDeletedFromTheirPages(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
        $this->client->request('GET', '/admin/templates/new');
        $this->client->submitForm('Create', ['template[name]' => 'Web project']);
        $template = $this->entity(ProjectTemplate::class, ['name' => 'Web project']) ?? self::fail('No template was created.');
        $base = '/admin/templates/'.$template->getId();
        $this->client->request('GET', $base.'/trackers/new');
        $this->client->submitForm('Create', ['tracker[name]' => 'Bug', 'tracker[workflowId]' => 'bug']);
        $tracker = $this->entity(TemplateTracker::class, ['name' => 'Bug']) ?? self::fail('No tracker was created.');
        $commands = self::getContainer()->get(CommandBus::class);
        $commands->dispatch(new CreateTemplateRule((int) $template->getId(), 'Thanks', null, self::THANKS));
        $commands->dispatch(new CreateTemplateRule((int) $template->getId(), 'Thanks for the bug', (int) $tracker->getId(), self::THANKS));

        $crawler = $this->client->request('GET', $base.'/automations');
        self::assertSelectorTextContains('main', 'Bug', 'the tracker a rule is limited to');
        $this->client->submit($crawler->filter('form[action$="/deactivate"]')->first()->form());
        self::assertResponseRedirects($base.'/automations');
        self::assertFalse($this->entity(TemplateRule::class, ['name' => 'Thanks'])?->isActive());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Rule deactivated.');
        $this->client->submit($crawler->filter('form[action$="/activate"]')->first()->form());
        self::assertTrue($this->entity(TemplateRule::class, ['name' => 'Thanks'])?->isActive());

        $crawler = $this->client->request('GET', $base.'/trackers/'.$tracker->getId());
        self::assertSelectorTextContains('main', 'The automation rule limited to it is deleted with it.');
        $this->client->submit($crawler->filter('form[action$="/trackers/'.$tracker->getId().'/delete"]')->form());
        self::assertResponseRedirects($base);
        self::assertNull($this->entity(TemplateTracker::class, ['name' => 'Bug']));
        self::assertNull($this->entity(TemplateRule::class, ['name' => 'Thanks for the bug']), 'the rule limited to it went with it');
        self::assertNotNull($this->entity(TemplateRule::class, ['name' => 'Thanks']));
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     *
     * @return T|null
     */
    private function entity(string $class, array $criteria): ?object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository($class)->findOneBy($criteria);
    }
}
