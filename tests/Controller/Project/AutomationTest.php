<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\AutomationRule;
use App\Entity\Membership;
use App\Entity\Team;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Membership\SetReferent;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\Command\Team\RevokeTeamAccess;
use App\Message\CommandBus;
use App\Repository\AutomationRuleRepository;
use App\Repository\TeamAccessRepository;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The automations pages of the shop: Thomas manages Maison Dupont, Nadia
 * develops on the shop, which has no referent.
 */
final class AutomationTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testOnlyWhoConfiguresTheProjectReachesItsRules(): void
    {
        $rule = $this->rule();

        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));
        foreach (['', '/new', '/runs', '/'.$rule->getId()] as $page) {
            $this->client->request('GET', '/MD/SPT/settings/automations'.$page);
            self::assertResponseStatusCodeSame(403);
        }

        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        $this->client->request('GET', '/MD/SPT/settings/general');
        self::assertSelectorExists('nav a[href="/MD/SPT/settings/automations"]');
        $this->client->request('GET', '/INT/SPT/settings/automations/'.$rule->getId());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheListSaysEachRuleInOneSentenceAndWhatItLacks(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        $this->client->request('GET', '/MD/SPT/settings/automations');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'This project has no rule yet.');
        self::assertSelectorExists('a[href="/MD/SPT/settings/automations/new"]');

        $rule = $this->rule();
        $this->client->request('GET', '/MD/SPT/settings/automations');
        self::assertSelectorTextContains('main', 'When an issue is created, if it is assigned to nobody: assign it to the Project manager referent.');
        self::assertSelectorTextContains('main', 'This project has no Project manager referent');
        self::assertSelectorTextContains('main', 'Never run yet');

        $this->client->request('GET', '/MD/SPT/settings/automations/'.$rule->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="automation_rule[name]"][value="New issue to the manager"]');
    }

    public function testADesignatedReferentWhoseAccountIsDeactivatedIsNamed(): void
    {
        $this->rule();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $thomas = $this->scene->user('thomas.girard@cairn.test');
        $access = $entityManager->getRepository(Membership::class)->findOneBy(['user' => $thomas]);
        self::assertNotNull($access);
        self::getContainer()->get(CommandBus::class)->dispatch(new SetReferent((int) $access->getId(), true));

        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
        $this->client->request('GET', '/MD/SPT/settings/automations');
        self::assertSelectorNotExists('main [role="status"]', 'a referent who answers: nothing to say');

        $thomas->deactivate();
        $entityManager->flush();
        $this->client->request('GET', '/MD/SPT/settings/automations');
        self::assertSelectorTextContains('main', 'The Project manager referent, Thomas Girard, has a deactivated account');
        $this->client->request('GET', '/MD/SPT/access');
        self::assertSelectorTextContains('main', 'Deactivated');
    }

    public function testARuleThatAimsAtATeamSaysSoAndWarnsWhenTheTeamHoldsNoRoleHere(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(Team::class, $team);
        $commands->dispatch(new GrantTeamAccess((int) $team->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $rule = $commands->dispatch(new CreateRule((int) $this->scene->shop->getId(), 'New issue to hosting', null, [
            'trigger' => ['type' => 'issue_created'],
            'conditions' => [],
            'actions' => [['type' => 'assign', 'params' => ['target' => 'team:'.$team->getId()]]],
        ]));
        self::assertInstanceOf(AutomationRule::class, $rule);
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));

        $this->client->request('GET', '/MD/SPT/settings/automations');
        self::assertSelectorTextContains('main', 'When an issue is created: assign it to the team Hosting.');
        self::assertSelectorTextNotContains('main', 'holds no role on this project');

        $crawler = $this->client->request('GET', '/MD/SPT/settings/automations/'.$rule->getId());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('optgroup[label="A team"] option[value="team:'.$team->getId().'"]'), 'the teams are offered among the targets');

        $access = self::getContainer()->get(TeamAccessRepository::class)->findOne($team, $this->scene->dupont);
        $commands->dispatch(new RevokeTeamAccess((int) $access?->getId()));
        $this->client->request('GET', '/MD/SPT/settings/automations');
        self::assertSelectorTextContains('main', 'The team Hosting holds no role on this project');
        self::assertSelectorExists('main a[href="/MD/SPT/access"]');

        $crawler = $this->client->request('GET', '/MD/SPT/settings/automations/'.$rule->getId());
        $chosen = $crawler->filter('option[value="team:'.$team->getId().'"][selected]');
        self::assertCount(1, $chosen, 'the form still shows the team the rule names');
        self::assertSame('Hosting (no role on this project)', $chosen->text());
    }

    public function testARuleIsSwitchedOffInPlace(): void
    {
        $rule = $this->rule();
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        $crawler = $this->client->request('GET', '/MD/SPT/settings/automations');

        $this->client->submit($crawler->filter('form[action$="/'.$rule->getId().'/deactivate"]')->form());
        self::assertResponseRedirects('/MD/SPT/settings/automations');
        self::assertFalse($this->rules()[0]->isActive());

        $this->client->followRedirect();
        self::assertSelectorExists('form[action$="/'.$rule->getId().'/activate"] input[type="checkbox"]:not([checked])');
        self::assertSelectorTextNotContains('main', 'has no Project manager referent', 'a rule switched off lacks nothing');
    }

    public function testTheHistoryTellsWhatEachRuleDid(): void
    {
        $rule = $this->rule();
        $this->scene->bug('Empty basket after payment');
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));

        $this->client->request('GET', '/MD/SPT/settings/automations/runs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody tr', 'New issue to the manager');
        self::assertSelectorTextContains('tbody tr', 'Empty basket after payment');
        self::assertSelectorTextContains('tbody tr', 'created it');
        self::assertSelectorTextContains('tbody tr', 'Skipped');
        self::assertSelectorTextContains('tbody tr', 'no Project manager referent');

        $this->client->request('GET', '/MD/SPT/settings/automations/runs?outcome=applied&rule='.$rule->getId());
        self::assertSelectorTextContains('main', 'No execution yet.');

        $this->client->request('GET', '/MD/SPT/settings/automations');
        self::assertSelectorTextContains('main', 'Last execution');
    }

    private function rule(): AutomationRule
    {
        return self::getContainer()->get(CommandBus::class)->dispatch(new CreateRule(
            (int) $this->scene->shop->getId(),
            'New issue to the manager',
            null,
            [
                'trigger' => ['type' => 'issue_created'],
                'conditions' => [['type' => 'assignee', 'params' => ['is' => 'nobody']]],
                'actions' => [['type' => 'assign', 'params' => ['target' => 'referent:project_manager']]],
            ],
        ));
    }

    /**
     * @return list<AutomationRule>
     */
    private function rules(): array
    {
        return self::getContainer()->get(AutomationRuleRepository::class)->findByProject($this->scene->shop);
    }
}
