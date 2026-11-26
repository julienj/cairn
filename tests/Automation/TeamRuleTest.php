<?php

declare(strict_types=1);

namespace App\Tests\Automation;

use App\Automation\AutomationException;
use App\Automation\Definition\ActionType;
use App\Automation\Definition\ConditionType;
use App\Automation\Definition\Target;
use App\Automation\Definition\TriggerType;
use App\Automation\RuleContexts;
use App\Automation\TeamsWithoutAccess;
use App\Entity\AutomationRule;
use App\Entity\AutomationRun;
use App\Entity\Enum\JournalEntryType;
use App\Entity\Enum\RunOutcome;
use App\Entity\IssueTeamSubscription;
use App\Entity\JournalEntry;
use App\Entity\ProjectTemplate;
use App\Entity\Team;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\DeleteTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\Command\Team\RevokeTeamAccess;
use App\Message\CommandBus;
use App\MessageHandler\Command\Team\TeamException;
use App\Repository\TeamAccessRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The rules that aim at a team. Thomas manages the shop, Claire is the
 * client; the hosting team (Marc) holds a role on the workspace, the
 * support team holds none.
 */
final class TeamRuleTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private EntityManagerInterface $entityManager;
    private Team $hosting;
    private Team $support;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
        $hosting = $this->commands->dispatch(new CreateTeam('Hosting', true));
        $support = $this->commands->dispatch(new CreateTeam('Support', true));
        \assert($hosting instanceof Team && $support instanceof Team);
        $this->commands->dispatch(new AddTeamMember((int) $hosting->getId(), (int) $this->fixtures->access->user('marc@agency.test', internal: true)->getId()));
        $this->commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->fixtures->access->workspace->getId(), null, 'developer'));
        $this->hosting = $hosting;
        $this->support = $support;
    }

    public function testANewIssueIsGivenToATeamWhichThenFollowsIt(): void
    {
        $rule = $this->newIssuesTo($this->hosting);

        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertSame($this->hosting, $issue->getAssigneeTeam());
        self::assertNull($issue->getAssignee());
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue, 'type' => JournalEntryType::Changed], ['id' => 'DESC']);
        self::assertSame(['field' => 'assignee', 'from' => null, 'to' => 'Hosting', 'rule' => 'New issue to Hosting'], $entry?->getData());
        self::assertNull($entry->getAuthor(), 'signed by the rule, not by a person');
        self::assertSame(1, $this->entityManager->getRepository(IssueTeamSubscription::class)->count(['issue' => $issue, 'team' => $this->hosting]));
        $runs = $this->runs($rule);
        self::assertSame(RunOutcome::Applied, $runs[0]->getOutcome());
        self::assertSame('Hosting', $runs[0]->getSteps()[0]['value']);
    }

    public function testARuleOfAProjectNamesTheTeamsThatHoldARoleThereAndATemplateAnyTeam(): void
    {
        try {
            $this->newIssuesTo($this->support);
            self::fail('A rule of the project aims at a team without a role on it.');
        } catch (AutomationException) {
        }

        $contexts = self::getContainer()->get(RuleContexts::class);
        self::assertSame([(int) $this->hosting->getId() => 'Hosting'], $contexts->ofProject($this->fixtures->access->shop)->teams);
        $template = new ProjectTemplate('Maintenance');
        $this->entityManager->persist($template);
        $this->entityManager->flush();
        self::assertEqualsCanonicalizing(['Hosting', 'Support'], array_values($contexts->ofTemplate($template)->teams), 'a team is the same on every project');
    }

    public function testATeamWithoutARoleOnTheProjectIsSkippedAndThePageOfTheRulesSaysItFirst(): void
    {
        $rule = $this->newIssuesTo($this->hosting);
        $missing = self::getContainer()->get(TeamsWithoutAccess::class);
        self::assertSame([], $missing->of($this->fixtures->access->shop, [$rule]));

        $access = self::getContainer()->get(TeamAccessRepository::class)->findOne($this->hosting, $this->fixtures->access->workspace);
        $this->commands->dispatch(new RevokeTeamAccess((int) $access?->getId()));
        self::assertSame([(int) $this->hosting->getId() => 'Hosting'], $missing->of($this->fixtures->access->shop, [$rule]));
        $context = self::getContainer()->get(RuleContexts::class)->ofProject($this->fixtures->access->shop);
        self::assertSame([(int) $this->hosting->getId() => 'Hosting'], $context->teams, 'the rule keeps the team it names');
        self::assertSame([(int) $this->hosting->getId()], $context->idleTeams);

        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertNull($issue->getAssigneeTeam(), 'the rule never fails the command that triggered it');
        $runs = $this->runs($rule);
        self::assertSame(RunOutcome::Skipped, $runs[0]->getOutcome());
        self::assertSame([['action' => 'assign', 'result' => 'skipped', 'value' => 'Hosting', 'reason' => 'not_a_member']], $runs[0]->getSteps());
    }

    public function testARuleMakesATeamFollowAnIssueOnce(): void
    {
        $rule = $this->rule('Hosting follows new issues', [[ActionType::Subscribe, ['target' => Target::team((int) $this->hosting->getId())]]]);

        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertNull($issue->getAssigneeTeam(), 'following is not being given the issue');
        self::assertSame(1, $this->entityManager->getRepository(IssueTeamSubscription::class)->count(['issue' => $issue, 'team' => $this->hosting]));
        self::assertSame(RunOutcome::Applied, $this->runs($rule)[0]->getOutcome());
    }

    public function testATeamARuleAimsAtIsNotDeleted(): void
    {
        $this->newIssuesTo($this->hosting);
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), assigneeId: UpdateIssue::NOBODY));

        try {
            $this->commands->dispatch(new DeleteTeam((int) $this->hosting->getId()));
            self::fail('A team a rule aims at was deleted.');
        } catch (TeamException $e) {
            self::assertStringContainsString('New issue to Hosting (Online shop)', $e->getMessage());
        }

        $this->commands->dispatch(new DeleteTeam((int) $this->support->getId()));
        self::assertNull($this->entityManager->getRepository(Team::class)->findOneBy(['name' => 'Support']), 'a team no rule names goes');
    }

    private function newIssuesTo(Team $team): AutomationRule
    {
        return $this->rule('New issue to '.$team->getName(), [[ActionType::Assign, ['target' => Target::team((int) $team->getId())]]], [[ConditionType::Assignee, ['is' => ConditionType::NOBODY]]]);
    }

    /**
     * @param list<array{ActionType, array<string, mixed>}>    $actions
     * @param list<array{ConditionType, array<string, mixed>}> $conditions
     */
    private function rule(string $name, array $actions, array $conditions = []): AutomationRule
    {
        $step = static fn (array $step): array => ['type' => $step[0]->value, 'params' => $step[1]];

        return $this->commands->dispatch(new CreateRule(
            (int) $this->fixtures->access->shop->getId(),
            $name,
            null,
            ['trigger' => $step([TriggerType::IssueCreated, []]), 'conditions' => array_map($step, $conditions), 'actions' => array_map($step, $actions)],
        ));
    }

    /**
     * @return list<AutomationRun>
     */
    private function runs(AutomationRule $rule): array
    {
        return array_values($this->entityManager->getRepository(AutomationRun::class)->findBy(['rule' => $rule], ['id' => 'ASC']));
    }
}
