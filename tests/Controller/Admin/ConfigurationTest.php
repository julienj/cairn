<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Config\ConfigRegistry;
use App\Config\ConfigValidator;
use App\Config\Definition\InstanceDefinition;
use App\Entity\Enum\PlaceStatus;
use App\Entity\Issue;
use App\Factory\UserFactory;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Config\AddWorkflowPlace;
use App\Message\Command\Config\CreateWorkflow;
use App\Message\Command\Template\CreateTemplate;
use App\Message\CommandBus;
use App\Security\Permission;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The configuration pages, on the starter configuration the scene
 * installs: four roles, three workflows, the shop's ten issues. What they
 * change is read back from the configuration, as the rest of the
 * application reads it.
 */
final class ConfigurationTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const array FRAME = ['HTTP_TURBO_FRAME' => 'modal'];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Whatever the pages changed, what is left must be a configuration the application can run.
     */
    protected function tearDown(): void
    {
        self::assertSame([], array_map(strval(...), (new ConfigValidator())->validate($this->definition())), 'the pages left a sound configuration');
        parent::tearDown();
    }

    public function testOnlyAdministratorsReadIt(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $this->client->loginUser($scene->user('thomas.girard@cairn.test'));

        $this->client->request('GET', '/admin/configuration/roles');
        self::assertResponseStatusCodeSame(403);

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Config:PermissionMatrix')->actingAs($scene->user('thomas.girard@cairn.test'))->render();
    }

    public function testTheRolesAndTheirRightsAreListed(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration');
        self::assertResponseRedirects('/admin/configuration/roles');
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('aside', 'Roles and rights', 'the configuration has its own section in the navigation');
        self::assertSelectorTextContains('aside', 'Steps');
        self::assertSelectorTextContains('#config-roles-project_manager', 'Project manager');
        self::assertSelectorTextContains('#config-roles-project_manager', 'Internal users only');
        self::assertSelectorTextContains('#config-roles-project_manager', 'Free workflow');
        self::assertSelectorTextContains('#config-roles-client', 'Guided workflow');
        self::assertSelectorTextContains('#config-roles-project_manager', '1 access', 'Thomas on Maison Dupont');

        self::assertSame(['Issues', 'Tracking', 'Comments', 'Project', 'Project configuration', 'Budgets'], $crawler->filter('main tbody th[scope=colgroup]')->each(static fn ($title): string => $title->text()), 'the rights come group by group');
        $tracking = $crawler->filter('main tbody')->reduce(static fn ($group): bool => str_starts_with($group->text(), 'Tracking'));
        self::assertStringContainsString('Assign issues', $tracking->text());
        self::assertStringNotContainsString('Make an issue private', $tracking->text(), 'with the issues');

        $assign = $crawler->filter('main tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Assign issues'));
        self::assertCount(1, $assign);
        // Project manager, Developer, Client, Client (read-only)
        self::assertSame([true, true, false, false], $assign->filter('input[type=checkbox]')->each(static fn ($box): bool => null !== $box->attr('checked')));

        $private = $crawler->filter('main tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Make an issue private'));
        self::assertCount(2, $private->filter('input[type=checkbox]'), 'a right for internal roles is not offered to the roles open to external users');
        $delete = $crawler->filter('main tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Delete an issue'));
        self::assertCount(4, $delete->filter('input[type=checkbox]'), 'deleting is for the administrators to grant, to any role');
    }

    public function testARightIsTickedInPlace(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $matrix = $this->createLiveComponent('Config:PermissionMatrix')->actingAs($scene->user('julie.admin@cairn.test'));

        $matrix->call('toggle', ['role' => 'client', 'permission' => Permission::IssueRank->value]);
        self::assertContains('client', $this->definition()->rolesGranted(Permission::IssueRank));

        $matrix->call('toggle', ['role' => 'client', 'permission' => Permission::IssueRank->value]);
        self::assertNotContains('client', $this->definition()->rolesGranted(Permission::IssueRank));

        $matrix->call('toggle', ['role' => 'client', 'permission' => Permission::IssueSetPrivate->value]);
        self::assertStringContainsString('open to external users', $matrix->render()->toString(), 'a refusal is said in place');
    }

    public function testTheChoicesOfAFieldAreArrangedInPlace(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $choices = $this->createLiveComponent('Config:Choices', ['field' => 'environment'])->actingAs($scene->user('julie.admin@cairn.test'));
        $options = fn (): array => $this->definition()->field('environment')->options;

        // A choice keeps the value issues store when its label changes; a new one gets it from its label, and comes last.
        $choices->call('edit', ['value' => 'prod'])->set('label', 'Live')->call('rename');
        $choices->set('adding', 'Local machine')->call('add');
        self::assertSame(['prod' => 'Live', 'preprod' => 'Pre-production', 'local_machine' => 'Local machine'], $options());

        $choices->call('move', ['id' => 'local_machine', 'position' => 0]);
        self::assertSame(['local_machine', 'prod', 'preprod'], array_keys($options()));

        // A label is needed, and said so in place.
        $choices->call('edit', ['value' => 'prod'])->set('label', ' ')->call('rename');
        self::assertStringContainsString('each with a label', $choices->render()->toString());
        self::assertSame('Live', $options()['prod']);

        // Taken out once confirmed; the last one stays, a field that offers choices having some.
        $choices->call('askRemove', ['value' => 'preprod']);
        self::assertStringContainsString('The issues that hold it will show its value', $choices->render()->toString(), 'asked before it is taken out');
        $choices->call('remove');
        $choices->call('askRemove', ['value' => 'prod'])->call('remove');
        $choices->call('askRemove', ['value' => 'local_machine'])->call('remove');
        self::assertStringContainsString('needs at least one choice', $choices->render()->toString());
        self::assertSame(['local_machine'], array_keys($options()));

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Config:Choices', ['field' => 'environment'])->actingAs($scene->user('thomas.girard@cairn.test'))->render();
    }

    public function testAListIsReorderedInPlace(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $admin = $scene->user('julie.admin@cairn.test');

        $this->createLiveComponent('Config:Order', ['kind' => 'roles'])->actingAs($admin)->call('move', ['id' => 'client', 'position' => 0]);
        self::assertSame(['client', 'project_manager', 'developer', 'client_readonly'], $this->definition()->roleIds());

        $this->createLiveComponent('Config:Order', ['kind' => 'workflow_places', 'workflow' => 'task'])->actingAs($admin)->call('move', ['id' => 'done', 'position' => 1]);
        self::assertSame(['new', 'done', 'in_progress', 'blocked', 'to_test', 'to_release', 'declined'], $this->definition()->workflow('task')->places);

        $this->createLiveComponent('Config:Order', ['kind' => 'transitions', 'workflow' => 'task'])->actingAs($admin)->call('move', ['id' => 'cancel', 'position' => 0]);
        self::assertSame('cancel', array_key_first($this->definition()->workflow('task')->transitions));
    }

    public function testARoleIsCreatedChangedAndDeletedInADialog(): void
    {
        $this->signInAsAdmin();

        // Asked for the frame, the dialog comes alone.
        $this->client->request('GET', '/admin/configuration/roles/new', server: self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('aside');
        self::assertSelectorExists('turbo-frame#modal form');

        $this->client->request('GET', '/admin/configuration/roles/new');
        $this->client->submitForm('Create', ['role[label]' => ' ']);
        self::assertResponseStatusCodeSame(422);

        $this->client->submitForm('Create', ['role[label]' => 'Auditor', 'role[followsNewIssues]' => true]);
        self::assertResponseRedirects('/admin/configuration/roles');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Role "Auditor" created.');
        self::assertSelectorTextContains('#config-roles-auditor', 'Subscribed to every new issue');

        $this->client->request('GET', '/admin/configuration/role/auditor');
        $this->client->submitForm('Save', ['role[label]' => 'External auditor', 'role[internalOnly]' => true, 'role[freeWorkflow]' => true]);
        $this->client->followRedirect();
        self::assertSame('External auditor', $this->definition()->role('auditor')->label);
        self::assertTrue($this->definition()->role('auditor')->internalOnly);
        self::assertTrue($this->definition()->role('auditor')->freeWorkflow);
        self::assertSelectorTextContains('#config-roles-auditor', 'Free workflow');

        $this->client->request('GET', '/admin/configuration/role/auditor');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Role "External auditor" deleted.');
        self::assertFalse($this->definition()->hasRole('auditor'));
    }

    public function testARefusalIsSaidInTheDialogOrOnThePage(): void
    {
        $this->signInAsAdmin();

        // Claire and Sophie, external, hold the role of client.
        $this->client->request('GET', '/admin/configuration/role/client');
        $this->client->submitForm('Save', ['role[internalOnly]' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#modal', 'accesses of external users or teams carry the role "Client"');

        // What cannot be deleted says why, beside a button that cannot be pressed.
        $this->client->request('GET', '/admin/configuration/role/client');
        self::assertSelectorTextContains('turbo-frame#modal', 'accesses carry the role "Client"');
        self::assertSelectorExists('turbo-frame#modal button[disabled]', 'the deletion is not offered');
        self::assertSelectorNotExists('#configuration-delete');

        $this->client->request('GET', '/admin/configuration/role/nobody');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAStepIsCreatedAndItsStatusIsThatOfItsIssues(): void
    {
        $scene = $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/places');
        self::assertSelectorTextContains('#config-places-to_test', 'To test');
        self::assertSelectorTextContains('#config-places-to_test', 'Bug, Feature, Internal task');
        self::assertSelectorTextContains('#config-places-to_test', '1 issue');
        self::assertSelectorTextContains('#config-places-needs_info', 'no issue');

        $this->client->request('GET', '/admin/configuration/places/new');
        $this->client->submitForm('Create', ['place[label]' => 'On hold', 'place[status]' => 'waiting']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('#config-places-on_hold', 'Used by no workflow');

        $this->client->request('GET', '/admin/configuration/place/to_test');
        $this->client->submitForm('Save', ['place[label]' => 'Acceptance', 'place[status]' => 'closed']);
        $this->client->followRedirect();

        self::assertSame('Acceptance', $this->definition()->place('to_test')->label);
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $tested = self::getContainer()->get(EntityManagerInterface::class)->find(Issue::class, $scene->issue('MD-SPT-4')->getId());
        self::assertInstanceOf(Issue::class, $tested);
        self::assertSame(PlaceStatus::Closed, $this->definition()->place($tested->getPlace())->status, 'the issue standing there is finished, by its step');
        self::assertNull($tested->getClosedAt(), 'and was not touched');

        // A step workflows use stays; one nothing uses goes.
        $this->client->request('GET', '/admin/configuration/place/to_test');
        self::assertSelectorTextContains('turbo-frame#modal', 'is used by: Bug, Feature, Internal task');
        self::assertSelectorExists('turbo-frame#modal button[disabled]', 'the deletion is not offered');
        self::assertSelectorNotExists('#configuration-delete');

        $this->client->request('GET', '/admin/configuration/place/on_hold');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertFalse($this->definition()->hasPlace('on_hold'));
    }

    /**
     * A key may be any word: the first step of the starter configuration is
     * `new`, and its address is not the one that creates a step.
     */
    public function testAStepWhoseKeyIsNewHasItsOwnDialog(): void
    {
        $this->signInAsAdmin();
        $steps = \count($this->definition()->places);

        $this->client->request('GET', '/admin/configuration/places');
        self::assertSelectorExists('#config-places-new a[href="/admin/configuration/place/new"]');

        $this->client->request('GET', '/admin/configuration/place/new');
        $this->client->submitForm('Save', ['place[label]' => 'Received']);
        $this->client->followRedirect();

        self::assertSame('Received', $this->definition()->place('new')->label);
        self::assertCount($steps, $this->definition()->places, 'no step was created');
    }

    /**
     * The commands keep the rows sound. One written otherwise (a migration,
     * SQL by hand) is read as it is: the pages open, and it is put right there.
     */
    public function testARowNoCommandWouldHaveWrittenIsReadAndPutRightFromTheAdministration(): void
    {
        $this->signInAsAdmin();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConnection()->executeStatement("UPDATE config_place SET label = '' WHERE id = 'to_test'");
        $entityManager->clear();
        self::getContainer()->get(ConfigRegistry::class)->reset();

        self::assertSame('', $this->definition()->place('to_test')->label, 'read as the row says');
        self::assertSame(['places.to_test.label: a label is required.'], array_map(strval(...), (new ConfigValidator())->validate($this->definition())), 'which is not a sound configuration');

        $this->client->request('GET', '/admin/configuration/places');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/configuration/place/to_test');
        $this->client->submitForm('Save', ['place[label]' => 'To test']);
        $this->client->followRedirect();
        self::assertSame('To test', $this->definition()->place('to_test')->label);
    }

    public function testFieldsAndLinkTypesAreManagedOnOnePage(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/fields');
        self::assertSelectorTextContains('#config-fields-environment', 'Single choice');
        self::assertSelectorTextContains('#config-fields-environment', 'Bug');
        self::assertSelectorTextContains('#config-fields-estimate', 'Internal users only');
        self::assertSelectorTextContains('#config-links-duplicates', 'Duplicated by');

        $this->client->request('GET', '/admin/configuration/fields/new');
        $this->client->submitForm('Create', ['field_settings[label]' => 'Browser', 'field_settings[type]' => 'select', 'field_settings[choices]' => "Chrome\nSafari"]);
        $this->client->followRedirect();
        self::assertSame(['chrome' => 'Chrome', 'safari' => 'Safari'], $this->definition()->field('browser_2')->options, 'the starter configuration already has a field `browser`');
        // No workflow asks for it yet: it may be deleted, and the dialog says what would go with it.
        $this->client->request('GET', '/admin/configuration/field/browser_2');
        self::assertSelectorTextContains('turbo-frame#modal', 'No issue holds a value of this one.');

        // Choices are asked only of a type that has some: shown as the type is chosen, left out otherwise.
        $this->client->request('GET', '/admin/configuration/fields/new');
        self::assertSelectorExists('form[data-controller="reveal"] [data-reveal-target="panel"][data-reveal-values="select choice"] textarea');
        // The browser does not send what it hides; sent all the same, choices for a type without any are refused, not dropped.
        $this->client->submitForm('Create', ['field_settings[label]' => 'Reference', 'field_settings[type]' => 'text', 'field_settings[choices]' => 'Left over']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#modal', 'Only a list or a single choice has choices');
        self::assertFalse($this->definition()->hasField('reference'));
        $this->client->request('GET', '/admin/configuration/field/url');
        self::assertSelectorNotExists('[name="field_settings[choices]"]');
        $this->client->submitForm('Save', ['field_settings[help]' => 'The page.']);
        $this->client->followRedirect();
        self::assertSame('The page.', $this->definition()->field('url')->help);

        // The choices are a list of their own: the dialog names them and leads to their page.
        $this->client->request('GET', '/admin/configuration/field/environment');
        self::assertSelectorNotExists('[name="field_settings[type]"]', 'the type is chosen once');
        self::assertSelectorNotExists('[name="field_settings[choices]"]');
        self::assertSelectorTextContains('turbo-frame#modal', 'Choices: Production, Pre-production.');
        self::assertSelectorExists('turbo-frame#modal a[href="/admin/configuration/field/environment/choices"]');
        $this->client->submitForm('Save', ['field_settings[help]' => 'Where it happens.']);
        $this->client->followRedirect();
        self::assertSame('Where it happens.', $this->definition()->field('environment')->help);
        self::assertSame(['prod' => 'Production', 'preprod' => 'Pre-production'], $this->definition()->field('environment')->options, 'left as they were');
        self::assertSelectorTextContains('#config-fields-environment', '2 choices');

        $this->client->request('GET', '/admin/configuration/field/environment/choices');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#config-choice-prod', 'Production');
        $this->client->request('GET', '/admin/configuration/field/url/choices');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/configuration/links/new');
        // How a link reads from the other issue is chosen when its type is created: a direction needs its second label.
        $this->client->submitForm('Create', ['link_type[label]' => 'Follows', 'link_type[nature]' => 'directed', 'link_type[inverseLabel]' => ' ']);
        self::assertResponseStatusCodeSame(422);
        $this->client->submitForm('Create', ['link_type[label]' => 'Follows', 'link_type[nature]' => 'directed', 'link_type[inverseLabel]' => 'Followed by']);
        $this->client->followRedirect();
        self::assertSame('Followed by', $this->definition()->link('follows')->inverseLabel);

        // Afterwards only its labels change: the dialog says which it is, and asks for the second label of one that has a direction.
        $this->client->request('GET', '/admin/configuration/link/follows');
        self::assertSelectorNotExists('[name="link_type[nature]"]');
        self::assertSelectorTextContains('turbo-frame#modal', 'This link has a direction');
        $this->client->submitForm('Save', ['link_type[inverseLabel]' => '']);
        self::assertResponseStatusCodeSame(422);
        $this->client->submitForm('Save', ['link_type[label]' => 'Comes after', 'link_type[inverseLabel]' => 'Comes before']);
        $this->client->followRedirect();
        self::assertSame('Comes before', $this->definition()->link('follows')->inverseLabel);

        $this->client->request('GET', '/admin/configuration/link/related');
        self::assertSelectorNotExists('[name="link_type[inverseLabel]"]');
        self::assertSelectorTextContains('turbo-frame#modal', 'This link reads the same from both issues');

        $this->client->request('GET', '/admin/configuration/link/follows');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertFalse($this->definition()->hasLink('follows'));
    }

    public function testAWorkflowShowsItsStepsActionsFieldsAndUsage(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/workflows');
        self::assertSelectorTextContains('#bug', 'Bug');
        self::assertSelectorTextContains('#bug', '1 category');
        self::assertSelectorExists('#bug a[href="/admin/configuration/workflow/bug"]');

        $crawler = $this->client->request('GET', '/admin/configuration/workflow/bug');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main a[href="/admin/configuration/workflows"]', 'back to the list');
        self::assertSelectorNotExists('[data-controller="workflow-diagram"]', 'the diagram has a page of its own');
        self::assertSelectorTextContains('main', 'A new issue starts on "New".');
        self::assertSelectorTextContains('#config-workflow_places-new', 'Initial');
        self::assertSelectorNotExists('#config-workflow_places-new a', 'the initial step does not leave');
        self::assertSelectorTextContains('#config-workflow_places-to_test', '1 issue');
        self::assertSelectorTextContains('#config-transitions-refuse', 'To test → In progress');
        self::assertSelectorTextContains('#config-transitions-refuse', 'Client');
        self::assertSelectorTextNotContains('#config-transitions-refuse', 'Project manager');
        self::assertSelectorTextContains('#config-transitions-refuse', 'comment required');
        self::assertSelectorTextContains('#workflow-field-environment', 'Environment');

        $crawler = $this->client->clickLink('Diagram');
        $source = $crawler->filter('[data-workflow-diagram-target="source"]')->text();
        self::assertStringContainsString('p_to_test -.->|"Refuse 💬 Client"| p_in_progress', $source, 'the graph, drawn by the browser: an action, its comment, its roles');
        self::assertStringContainsString('p_new -->|"Qualify Developer"| p_qualified', $source, 'the action that leads to the next step of the path is its arrow');
        self::assertStringNotContainsString('--> p_needs_info p_needs_info --> p_qualified', $source, 'a step beside the path is not on it');
        self::assertStringContainsString('p_in_progress -->|"Block 💬 Developer"| p_blocked', $source);
        self::assertStringNotContainsString('p_in_progress -.-> p_blocked', $source, 'an action already leads to the step from the one it stands beside: no other line');
        self::assertSelectorExists('main a[href="/admin/configuration/workflow/bug"]');

        $this->client->request('GET', '/admin/configuration/workflow/bug/usage');
        self::assertSelectorTextContains('main', 'Online shop');
        self::assertSelectorTextContains('main', 'MD-SPT');

        // A workflow that uses every step of the instance is not offered more.
        $this->client->request('GET', '/admin/configuration/workflow/bug');
        self::assertSelectorExists('main a[href="/admin/configuration/workflow/bug/places/add"]');
        self::getContainer()->get(CommandBus::class)->dispatch(new CreateWorkflow('Everything', 'new'));
        foreach (array_diff(array_keys($this->definition()->places), ['new']) as $place) {
            self::getContainer()->get(CommandBus::class)->dispatch(new AddWorkflowPlace('everything', $place));
        }
        $this->client->request('GET', '/admin/configuration/workflow/everything');
        self::assertSelectorNotExists('main a[href="/admin/configuration/workflow/everything/places/add"]');
        $this->client->request('GET', '/admin/configuration/workflow/everything/places/add');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/configuration/workflow/nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAWorkflowIsCreatedThenGivenItsStepsAndActions(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/workflows/new');
        $this->client->submitForm('Create', ['workflow[label]' => 'Support request', 'workflow[initial]' => 'new']);
        self::assertResponseRedirects('/admin/configuration/workflow/support_request');

        $this->client->request('GET', '/admin/configuration/workflow/support_request/places/add');
        self::assertSelectorNotExists('[name="add_places[places][]"][value="new"]', 'the steps it has are not offered again');
        $this->client->submitForm('Add the steps');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#modal', 'Tick at least one step.');

        // Several at once: they come after its columns, in the order of the instance.
        $this->client->submitForm('Add the steps', ['add_places[places][5]' => 'in_progress', 'add_places[places][9]' => 'done']);
        $this->client->followRedirect();
        self::assertSame(['new', 'in_progress', 'done'], $this->definition()->workflow('support_request')->places);

        $this->client->request('GET', '/admin/configuration/workflow/support_request/actions/new');
        $this->client->submitForm('Create', ['transition[label]' => 'Answer', 'transition[to]' => 'done']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('turbo-frame#modal', 'An action starts from at least one step.');

        // An action is for the roles held to the workflow: the manager, who moves freely, is not offered.
        self::assertSelectorExists('[name="transition[roles][]"][value="developer"]');
        self::assertSelectorNotExists('[name="transition[roles][]"][value="project_manager"]');
        $this->client->submitForm('Create', [
            'transition[label]' => 'Answer',
            'transition[from]' => ['new'],
            'transition[to]' => 'done',
            'transition[roles]' => ['developer'],
            'transition[style]' => 'primary',
            'transition[requiresComment]' => true,
            'transition[commentPrompt]' => 'What was done?',
        ]);
        self::assertResponseRedirects('/admin/configuration/workflow/support_request');

        $answer = $this->definition()->workflow('support_request')->transition('answer');
        self::assertSame(['new'], $answer->from);
        self::assertSame('done', $answer->to);
        self::assertSame('What was done?', $answer->commentPrompt);

        $this->client->request('GET', '/admin/configuration/workflow/support_request/action/answer');
        $this->client->submitForm('Save', ['transition[label]' => 'Reply', 'transition[requiresComment]' => false, 'transition[description]' => 'The request is answered.']);
        $this->client->followRedirect();
        $answer = $this->definition()->workflow('support_request')->transition('answer');
        self::assertSame('Reply', $answer->label);
        self::assertNull($answer->commentPrompt);
        self::assertSame('The request is answered.', $answer->description);

        $this->client->request('GET', '/admin/configuration/workflow/support_request/edit');
        $this->client->submitForm('Save', ['workflow[label]' => 'Support', 'workflow[initial]' => 'done']);
        $this->client->followRedirect();
        self::assertSame('done', $this->definition()->workflow('support_request')->initial);

        $this->client->request('GET', '/admin/configuration/workflow/support_request/action/answer');
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        self::assertSame([], $this->definition()->workflow('support_request')->transitions);

        $this->client->request('GET', '/admin/configuration/workflow/support_request/edit');
        $this->client->submitForm('Delete');
        self::assertResponseRedirects('/admin/configuration/workflows');
        self::assertFalse($this->definition()->hasWorkflow('support_request'));

        // A workflow categories follow stays, and says so on its page.
        $this->client->request('GET', '/admin/configuration/workflow/bug/edit');
        self::assertSelectorTextContains('turbo-frame#modal', 'A category follows the workflow "Bug"');
        self::assertSelectorExists('turbo-frame#modal button[disabled]', 'the deletion is not offered');
        self::assertSelectorNotExists('#configuration-delete');
    }

    public function testFreeingARoleSaysWhatItLeavesThenTakesItOutOfItsActions(): void
    {
        $scene = $this->signInAsAdmin();
        self::getContainer()->get(CommandBus::class)->dispatch(new CreateRule((int) $scene->shop->getId(), 'Thank the client', (int) $scene->bugs->getId(), [
            'trigger' => ['type' => 'transition_applied', 'params' => ['transition' => 'accept']],
            'actions' => [['type' => 'comment', 'params' => ['body' => 'Thank you.']]],
        ]));

        $this->client->request('GET', '/admin/configuration/role/project_manager');
        self::assertSelectorTextNotContains('turbo-frame#modal', 'for good', 'a role that moves freely has no action to leave');

        $this->client->request('GET', '/admin/configuration/role/client');
        self::assertSelectorTextContains('turbo-frame#modal', 'for good');
        self::assertSelectorTextContains('turbo-frame#modal', 'Accept (Bug)');
        self::assertSelectorTextContains('turbo-frame#modal', 'Thank the client (Online shop)', 'the client alone accepts: the rule would wait for nobody');

        $this->client->submitForm('Save', ['role[freeWorkflow]' => true]);
        $this->client->followRedirect();
        self::assertSame([], $this->definition()->workflow('bug')->transition('accept')->roles);
        self::assertSame(['developer'], $this->definition()->workflow('bug')->transition('provide_info')->roles, 'the other roles keep the action');

        $this->client->request('GET', '/admin/configuration/workflow/bug');
        self::assertSelectorTextContains('#config-transitions-accept', 'no role');
        $source = $this->client->clickLink('Diagram')->filter('[data-workflow-diagram-target="source"]')->text();
        self::assertStringNotContainsString('Accept', $source, 'an action nobody triggers is not drawn');
    }

    public function testAStepIsTakenOffThePathNextToTheDiagramWhichFollows(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $panel = $this->createLiveComponent('Config:WorkflowPath', ['workflow' => 'bug'])->actingAs($scene->user('julie.admin@cairn.test'));
        $source = static fn (string $render): string => html_entity_decode(new Crawler($render)->filter('[data-workflow-diagram-target="source"]')->text());

        $render = (string) $panel->render();
        self::assertStringContainsString('id="workflow-off-path-needs_info"', $render, 'the steps off the path, each with where it is reached from');
        self::assertStringNotContainsString('data-live-action-param="takeOff" data-live-place-param="new"', $render, 'the step the issues start on stays on the path');
        self::assertStringNotContainsString('data-live-action-param="takeOff" data-live-place-param="in_progress"', $render, 'nor does a step another is reached from leave it');
        self::assertStringNotContainsString('data-live-from-param="blocked"', $render, 'a step is reached from one of the path');

        // Taken off the path, a step is reached from the one of the path that came before it, until said otherwise.
        $render = (string) $panel->call('takeOff', ['place' => 'to_release'])->render();
        self::assertSame('to_test', $this->definition()->workflow('bug')->aside['to_release'] ?? null);
        self::assertStringNotContainsString('--> p_to_release p_to_release -->', $source($render), 'the diagram no longer has it on the path');

        $render = (string) $panel->call('attach', ['place' => 'to_release', 'from' => 'qualified'])->render();
        self::assertSame('qualified', $this->definition()->workflow('bug')->aside['to_release'] ?? null);
        self::assertStringContainsString('p_qualified -.-> p_to_release', $source($render), 'and hangs from the step it is reached from');

        $panel->call('attach', ['place' => 'to_release', 'from' => 'blocked']);
        self::assertStringContainsString('is reached from a step of the path', (string) $panel->render(), 'a refusal is said in place');
        self::assertSame('qualified', $this->definition()->workflow('bug')->aside['to_release'] ?? null);

        $panel->call('putBack', ['place' => 'to_release']);
        self::assertArrayNotHasKey('to_release', $this->definition()->workflow('bug')->aside);

        // What the menu does not offer is left alone: a step already off the path stays reached from where it was said.
        $panel->call('takeOff', ['place' => 'blocked']);
        self::assertSame('in_progress', $this->definition()->workflow('bug')->aside['blocked'] ?? null);
        $panel->call('takeOff', ['place' => 'new']);
        self::assertArrayNotHasKey('new', $this->definition()->workflow('bug')->aside);

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Config:WorkflowPath', ['workflow' => 'bug'])->actingAs($scene->user('thomas.girard@cairn.test'))->render();
    }

    public function testAStepLeavesAWorkflowOnceItsIssuesWereSentElsewhere(): void
    {
        $scene = $this->signInAsAdmin();

        self::getContainer()->get(CommandBus::class)->dispatch(new CreateRule((int) $scene->shop->getId(), 'Remind who was asked', (int) $scene->bugs->getId(), [
            'trigger' => ['type' => 'inactive_for', 'params' => ['days' => 10]],
            'conditions' => [['type' => 'place', 'params' => ['is' => ['needs_info']]]],
            'actions' => [['type' => 'comment', 'params' => ['body' => 'Any news?']]],
        ]));

        $this->client->request('GET', '/admin/configuration/workflow/bug/place/needs_info/remove');
        self::assertSelectorTextContains('turbo-frame#modal', 'An automation rule names this step');
        self::assertSelectorTextContains('turbo-frame#modal', 'Remind who was asked (Online shop)');
        self::assertSelectorTextContains('turbo-frame#modal', 'Deleted · Ask for details');
        self::assertSelectorTextContains('turbo-frame#modal', 'Deleted · Answer');
        self::assertSelectorTextContains('turbo-frame#modal', 'No longer starts from there · Reject');
        self::assertSelectorTextNotContains('turbo-frame#modal', 'must go elsewhere');

        $this->client->request('GET', '/admin/configuration/workflow/bug/place/to_test/remove');
        self::assertSelectorTextContains('turbo-frame#modal', 'An issue stands on this step');
        $this->client->submitForm('Remove the step and move 1 issue', ['workflow_place[place]' => 'in_progress']);
        self::assertResponseRedirects('/admin/configuration/workflow/bug');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '"To test" left the workflow, and its issue moved.');

        $bug = $this->definition()->workflow('bug');
        self::assertFalse($bug->hasPlace('to_test'));
        self::assertArrayNotHasKey('deliver', $bug->transitions);
        self::assertArrayNotHasKey('accept', $bug->transitions);
        self::assertTrue($this->definition()->workflow('task')->hasPlace('to_test'), 'the other workflows keep the step');

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $moved = self::getContainer()->get(EntityManagerInterface::class)->find(Issue::class, $scene->issue('MD-SPT-4')->getId());
        self::assertInstanceOf(Issue::class, $moved);
        self::assertSame('in_progress', $moved->getPlace());

        $this->client->request('GET', '/admin/configuration/workflow/bug/place/new/remove');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFieldsOfAWorkflowAreArrangedInPlace(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $fields = $this->createLiveComponent('Config:WorkflowFields', ['workflow' => 'task'])->actingAs($scene->user('julie.admin@cairn.test'));
        self::assertCount(0, $this->definition()->workflow('task')->fields, 'the scene gives tasks no field');

        $fields->set('adding', 'url')->call('add');
        $fields->set('adding', 'goal')->call('add');
        $fields->call('toggle', ['field' => 'goal']);
        $fields->call('move', ['id' => 'goal', 'position' => 0]);

        $asked = $this->definition()->workflow('task')->fields;
        self::assertSame(['goal', 'url'], array_keys($asked));
        self::assertTrue($asked['goal']->required);
        self::assertFalse($asked['url']->required);

        $fields->call('remove', ['field' => 'url']);
        self::assertSame(['goal'], array_keys($this->definition()->workflow('task')->fields));
    }

    public function testEveryChangeIsWrittenInTheJournal(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/configuration/place/to_test');
        $this->client->submitForm('Save', ['place[status]' => 'closed']);
        $this->client->request('GET', '/admin/configuration/workflow/bug/place/needs_info/remove');
        $this->client->submitForm('Remove the step');
        // A refusal writes nothing.
        $this->client->request('GET', '/admin/configuration/role/client');
        $this->client->submitForm('Save', ['role[internalOnly]' => true]);

        $crawler = $this->client->request('GET', '/admin/configuration/history');
        self::assertSelectorTextContains('aside', 'History');
        self::assertSame([
            'Julie Admin Workflow "Bug": step "Info requested" removed.',
            'Julie Admin Step "To test" changed.',
            'Installation Configuration installed: 4 roles, 3 workflows, 12 steps, 7 fields, 3 link types.',
        ], $crawler->filter('main ol li')->each(static fn ($line): string => $line->filter('span')->eq(0)->text().' '.$line->filter('span')->eq(1)->text()));
    }

    public function testAnInstanceWithoutConfigurationIsOfferedItsStarterConfiguration(): void
    {
        $this->client->loginUser(UserFactory::new()->admin()->create(['email' => 'first.admin@cairn.test']));

        $this->client->request('GET', '/admin/configuration/roles');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'This instance has no configuration yet');

        $this->client->submitForm('Install the starter configuration');
        self::assertResponseRedirects('/admin/configuration/workflows');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Starter configuration installed.');
        self::assertSelectorTextContains('#bug', 'Bug');
        self::assertSame(['project_manager', 'developer', 'client', 'partner'], $this->definition()->roleIds());
    }

    /**
     * The configuration comes with its template or not at all, and the page says so in its own words.
     */
    public function testAStarterConfigurationThatCannotBeInstalledLeavesNothing(): void
    {
        $this->client->loginUser(UserFactory::new()->admin()->create(['email' => 'first.admin@cairn.test']));
        // The kit makes a template of that name: it will be refused, once the configuration is written.
        self::getContainer()->get(CommandBus::class)->dispatch(new CreateTemplate('Web project'));

        $this->client->request('GET', '/admin/configuration/roles');
        $this->client->submitForm('Install the starter configuration');
        $this->client->followRedirect();

        self::assertSelectorTextContains('body', 'The starter configuration could not be installed, and nothing changed.');
        self::assertTrue($this->definition()->isEmpty(), 'the configuration was taken back with the template');
        self::assertSelectorTextContains('main', 'This instance has no configuration yet');
    }

    private function signInAsAdmin(): ShopScene
    {
        $scene = ShopScene::load(self::getContainer());
        $this->client->loginUser($scene->user('julie.admin@cairn.test'));

        return $scene;
    }

    private function definition(): InstanceDefinition
    {
        return self::getContainer()->get(ConfigRegistry::class)->definition();
    }
}
