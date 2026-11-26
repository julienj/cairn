<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Template;

use App\Automation\AutomationException;
use App\Automation\TeamsWithoutAccess;
use App\Entity\AutomationRule;
use App\Entity\Project;
use App\Entity\ProjectTemplate;
use App\Entity\Team;
use App\Entity\TemplateRule;
use App\Entity\TemplateTracker;
use App\Entity\Tracker;
use App\Message\Command\Project\CreateProject;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\DeleteTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\Command\Template\CreateTemplate;
use App\Message\Command\Template\CreateTemplateRule;
use App\Message\Command\Template\CreateTemplateTracker;
use App\Message\Command\Template\DeleteTemplate;
use App\Message\Command\Template\DeleteTemplateTracker;
use App\Message\Command\Template\GrantTemplateTeamAccess;
use App\Message\Command\Template\RevokeTemplateTeamAccess;
use App\Message\Command\Template\UpdateTemplate;
use App\Message\Command\Template\UpdateTemplateRule;
use App\Message\Command\Template\UpdateTemplateTracker;
use App\Message\CommandBus;
use App\MessageHandler\Command\Project\ProjectException;
use App\MessageHandler\Command\Template\TemplateException;
use App\Repository\AutomationRuleRepository;
use App\Repository\TeamAccessRepository;
use App\Repository\TemplateRuleRepository;
use App\Repository\TemplateTeamAccessRepository;
use App\Repository\TemplateTrackerRepository;
use App\Repository\TrackerRepository;
use App\Security\AccessControl;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The project templates: what one may hold, and what a project created
 * with one is given. The test configuration has one workflow, `bug`,
 * with the place `done`.
 */
final class TemplateHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private const array TO_THE_MANAGER = ['trigger' => ['type' => 'issue_created'], 'actions' => [['type' => 'assign', 'params' => ['target' => 'referent:project_manager']]]];
    private const array CLOSE = ['trigger' => ['type' => 'inactive_for', 'params' => ['days' => 45]], 'actions' => [['type' => 'move', 'params' => ['place' => 'done']]]];

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testATemplateHasANameOfItsOwn(): void
    {
        $template = $this->template('Web project');
        self::assertSame('Web project', $template->getName());
        $other = $this->template('Maintenance');

        $this->commands->dispatch(new UpdateTemplate((int) $template->getId(), 'Web project', 'For the sites we build.'));
        self::assertSame('For the sites we build.', $template->getDescription(), 'its own name is not taken');

        try {
            $this->commands->dispatch(new UpdateTemplate((int) $other->getId(), 'Web project'));
            self::fail('Two templates took the same name.');
        } catch (TemplateException $e) {
            self::assertEquals(TemplateException::nameTaken('Web project'), $e);
        }

        $this->expectExceptionObject(TemplateException::nameTaken('Web project'));
        $this->template(' Web project ');
    }

    public function testATrackerIsBoundToAWorkflowOfTheConfigurationAndOrdered(): void
    {
        $template = $this->template();
        $bugs = $this->tracker($template, 'Bug', 'bug');
        $features = $this->tracker($template, 'Feature', 'bug');
        $tasks = $this->tracker($template, 'Task', 'bug');
        self::assertSame([0, 1, 2], [$bugs->getPosition(), $features->getPosition(), $tasks->getPosition()]);

        $this->commands->dispatch(new UpdateTemplateTracker((int) $tasks->getId(), name: 'Internal task', private: true, position: 0));
        self::assertSame(['Internal task', 'Bug', 'Feature'], $this->trackerNames($template));
        self::assertTrue($tasks->isPrivate());

        $this->expectExceptionObject(TemplateException::unknownWorkflow('nonsense'));
        $this->tracker($template, 'Other', 'nonsense');
    }

    public function testARuleOfATemplateNamesNobody(): void
    {
        $template = $this->template();
        $rule = $this->rule($template, self::TO_THE_MANAGER);
        self::assertTrue($rule->isActive());
        self::assertNull($rule->getTracker());

        $this->expectException(AutomationException::class);
        $this->expectExceptionMessage('"target"');
        $this->rule($template, ['trigger' => ['type' => 'issue_created'], 'actions' => [['type' => 'assign', 'params' => ['target' => 'user:1']]]]);
    }

    public function testAPlaceOfAWorkflowNeedsATrackerOfTheTemplate(): void
    {
        $template = $this->template();
        $bugs = $this->tracker($template, 'Bug', 'bug');
        $elsewhere = $this->tracker($this->template('Other'), 'Bug', 'bug');

        $rule = $this->rule($template, self::CLOSE, (int) $bugs->getId());
        self::assertSame($bugs, $rule->getTracker());

        try {
            $this->rule($template, self::CLOSE);
            self::fail('A rule that moves an issue was kept without a tracker.');
        } catch (AutomationException $e) {
            self::assertEquals(AutomationException::needsTracker(), $e);
        }
        try {
            $this->commands->dispatch(new UpdateTemplateRule((int) $rule->getId(), trackerId: UpdateTemplateRule::NO_TRACKER));
            self::fail('The rule was taken off its tracker.');
        } catch (AutomationException $e) {
            self::assertEquals(AutomationException::needsTracker(), $e);
        }

        $this->commands->dispatch(new UpdateTemplateRule((int) $rule->getId(), name: 'Closing', active: false));
        self::assertSame('Closing', $rule->getName());
        self::assertFalse($rule->isActive());

        $this->expectExceptionObject(AutomationException::unknownTracker());
        $this->rule($template, self::CLOSE, (int) $elsewhere->getId());
    }

    public function testATrackerLeavesWithTheRulesLimitedToItAndATemplateWithEverything(): void
    {
        $template = $this->template();
        $bugs = $this->tracker($template, 'Bug', 'bug');
        $this->tracker($template, 'Feature', 'bug');
        $this->rule($template, self::TO_THE_MANAGER);
        $this->rule($template, self::CLOSE, (int) $bugs->getId());

        $this->commands->dispatch(new DeleteTemplateTracker((int) $bugs->getId()));
        $this->entityManager->clear();
        $template = $this->entityManager->find(ProjectTemplate::class, $template->getId()) ?? self::fail('The template vanished.');
        self::assertSame(['Feature'], $this->trackerNames($template));
        self::assertSame([0], array_map(static fn (TemplateTracker $tracker): int => $tracker->getPosition(), self::getContainer()->get(TemplateTrackerRepository::class)->findByTemplate($template)));
        self::assertCount(1, self::getContainer()->get(TemplateRuleRepository::class)->findByTemplate($template));

        $this->commands->dispatch(new DeleteTemplate((int) $template->getId()));
        $this->entityManager->clear();
        self::assertSame([], $this->entityManager->getRepository(ProjectTemplate::class)->findAll());
        self::assertSame([], $this->entityManager->getRepository(TemplateTracker::class)->findAll());
        self::assertSame([], $this->entityManager->getRepository(TemplateRule::class)->findAll());
    }

    public function testAProjectCreatedWithATemplateIsGivenItsTrackersAndItsRules(): void
    {
        $template = $this->template();
        $bugs = $this->tracker($template, 'Bug', 'bug', 'Something no longer works.');
        $this->tracker($template, 'Feature', 'bug');
        $this->tracker($template, 'Task', 'bug', private: true);
        $this->rule($template, self::TO_THE_MANAGER, name: 'New issue to the manager');
        $this->rule($template, self::CLOSE, (int) $bugs->getId(), 'Closing', active: false);
        $workspace = (new AccessFixtures($this->entityManager))->workspace;

        $project = $this->commands->dispatch(new CreateProject((int) $workspace->getId(), 'Application', 'APP', (int) $template->getId()));
        self::assertInstanceOf(Project::class, $project);
        $this->entityManager->clear();
        $project = $this->entityManager->find(Project::class, $project->getId()) ?? self::fail('The project vanished.');

        $trackers = self::getContainer()->get(TrackerRepository::class)->findByProject($project);
        self::assertSame(
            [['Bug', 'bug', 'Something no longer works.', false, 0], ['Feature', 'bug', null, false, 1], ['Task', 'bug', null, true, 2]],
            array_map(static fn (Tracker $tracker): array => [$tracker->getName(), $tracker->getWorkflowId(), $tracker->getDescription(), $tracker->isPrivate(), $tracker->getPosition()], $trackers),
        );
        $rules = self::getContainer()->get(AutomationRuleRepository::class)->findByProject($project);
        self::assertSame(['New issue to the manager', 'Closing'], array_map(static fn (AutomationRule $rule): string => $rule->getName(), $rules));
        self::assertSame([true, false], array_map(static fn (AutomationRule $rule): bool => $rule->isActive(), $rules));
        self::assertNull($rules[0]->getTracker());
        self::assertSame($trackers[0], $rules[1]->getTracker(), 'limited to the tracker made from the one it named');
        self::assertEquals(self::CLOSE + ['conditions' => []], $rules[1]->getDefinition()->toArray(), 'copied as it is');

        // The two live apart: what the template becomes changes nothing in the project.
        $this->commands->dispatch(new DeleteTemplate((int) $template->getId()));
        $this->entityManager->clear();
        $project = $this->entityManager->find(Project::class, $project->getId()) ?? self::fail('The project vanished.');
        self::assertCount(3, self::getContainer()->get(TrackerRepository::class)->findByProject($project));
        self::assertCount(2, self::getContainer()->get(AutomationRuleRepository::class)->findByProject($project));
    }

    public function testATemplateGivesTeamsARoleOnTheProjectsMadeFromItSoThatItsRulesFindThem(): void
    {
        $fixtures = new AccessFixtures($this->entityManager);
        $marc = $fixtures->user('marc@agency.test', internal: true);
        $hosting = $this->team('Hosting', true);
        $support = $this->team('Support', true);
        $accounting = $this->team('Accounting', false);
        $this->commands->dispatch(new AddTeamMember((int) $hosting->getId(), (int) $marc->getId()));
        $template = $this->template();
        $templateId = (int) $template->getId();
        $this->rule($template, ['trigger' => ['type' => 'issue_created'], 'actions' => [['type' => 'assign', 'params' => ['target' => 'team:'.$hosting->getId()]]]], name: 'New issue to hosting');
        $accesses = self::getContainer()->get(TemplateTeamAccessRepository::class);

        $this->commands->dispatch(new GrantTemplateTeamAccess($templateId, (int) $hosting->getId(), 'project_manager'));
        $this->commands->dispatch(new GrantTemplateTeamAccess($templateId, (int) $hosting->getId(), 'developer'));
        $this->commands->dispatch(new GrantTemplateTeamAccess($templateId, (int) $support->getId(), 'developer'));
        $this->commands->dispatch(new GrantTemplateTeamAccess($templateId, (int) $accounting->getId(), 'client'));
        self::assertSame(['Accounting' => 'client', 'Hosting' => 'developer', 'Support' => 'developer'], $this->accessesOf($template), 'a team is named once: its role is changed');
        try {
            $this->commands->dispatch(new GrantTemplateTeamAccess($templateId, (int) $accounting->getId(), 'developer'));
            self::fail('A template gives an internal-only role to an external team.');
        } catch (TemplateException $e) {
            self::assertStringContainsString('which is external', $e->getMessage());
        }

        // The support team holds as much on the workspace already: the project gives it nothing more.
        $this->commands->dispatch(new GrantTeamAccess((int) $support->getId(), $fixtures->workspace->getId(), null, 'developer'));
        $project = $this->commands->dispatch(new CreateProject((int) $fixtures->workspace->getId(), 'Application', 'APP', $templateId));
        self::assertInstanceOf(Project::class, $project);

        $teamAccesses = self::getContainer()->get(TeamAccessRepository::class);
        self::assertSame('developer', $teamAccesses->findOne($hosting, $project)?->getRole());
        self::assertSame('client', $teamAccesses->findOne($accounting, $project)?->getRole());
        self::assertNull($teamAccesses->findOne($support, $project));
        self::assertSame('developer', self::getContainer()->get(AccessControl::class)->roleOn($marc, $project), 'its members work on the project at once');
        self::assertSame([], self::getContainer()->get(TeamsWithoutAccess::class)->of($project, self::getContainer()->get(AutomationRuleRepository::class)->findByProject($project)), 'the rule finds its team');

        $this->commands->dispatch(new RevokeTemplateTeamAccess((int) $accesses->findOneBy(['template' => $template, 'team' => $accounting])?->getId()));
        self::assertSame('client', $teamAccesses->findOne($accounting, $project)?->getRole(), 'the project keeps what it was given');
        $this->commands->dispatch(new DeleteTeam((int) $support->getId()));
        self::assertSame(['Hosting' => 'developer'], $this->accessesOf($template), 'a team that goes leaves the templates');
        $this->commands->dispatch(new DeleteTemplate($templateId));
        self::assertSame(0, $accesses->count([]));
    }

    public function testAProjectIsNotCreatedWithATemplateThatDoesNotExist(): void
    {
        $workspace = (new AccessFixtures($this->entityManager))->workspace;

        $this->expectExceptionObject(ProjectException::notFound('Template', 999_999_999));
        $this->commands->dispatch(new CreateProject((int) $workspace->getId(), 'Application', 'APP', 999_999_999));
    }

    private function team(string $name, bool $internal): Team
    {
        $team = $this->commands->dispatch(new CreateTeam($name, $internal));
        self::assertInstanceOf(Team::class, $team);

        return $team;
    }

    /**
     * @return array<string, string> team name => role
     */
    private function accessesOf(ProjectTemplate $template): array
    {
        $roles = [];
        foreach (self::getContainer()->get(TemplateTeamAccessRepository::class)->findByTemplate($template) as $access) {
            $roles[$access->getTeam()->getName()] = $access->getRole();
        }

        return $roles;
    }

    private function template(string $name = 'Web project'): ProjectTemplate
    {
        $template = $this->commands->dispatch(new CreateTemplate($name));
        \assert($template instanceof ProjectTemplate);

        return $template;
    }

    private function tracker(ProjectTemplate $template, string $name, string $workflowId, ?string $description = null, bool $private = false): TemplateTracker
    {
        $tracker = $this->commands->dispatch(new CreateTemplateTracker((int) $template->getId(), $name, $workflowId, $description, $private));
        \assert($tracker instanceof TemplateTracker);

        return $tracker;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function rule(ProjectTemplate $template, array $definition, ?int $trackerId = null, string $name = 'Rule', bool $active = true): TemplateRule
    {
        $rule = $this->commands->dispatch(new CreateTemplateRule((int) $template->getId(), $name, $trackerId, $definition, $active));
        \assert($rule instanceof TemplateRule);

        return $rule;
    }

    /**
     * @return list<string>
     */
    private function trackerNames(ProjectTemplate $template): array
    {
        return array_map(static fn (TemplateTracker $tracker): string => $tracker->getName(), self::getContainer()->get(TemplateTrackerRepository::class)->findByTemplate($template));
    }
}
