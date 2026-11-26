<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\IssueList;

use App\Entity\Comment;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\User;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\IssueTagRepository;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The selection page on the shop of the scene: MD-SPT-1 and 2 are new
 * bugs, 4 waits for Claire's acceptance, 6 is an internal task, 7 a
 * feature. Nadia develops on
 * the shop, Thomas manages the workspace, Claire is its client, Marc
 * develops on the intranet only.
 */
final class SelectionTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private const string FROM = '/MD/SPT/issues?status%5B0%5D=open';

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testThePageShowsTheIssuesAndTheChangesOffered(): void
    {
        $render = (string) $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2')->render();

        self::assertStringContainsString('MD-SPT-1', $render);
        self::assertStringContainsString('MD-SPT-2', $render);
        self::assertStringContainsString('data-live-action-param="move"', $render);
        self::assertStringNotContainsString('data-live-action-param="move"', (string) $this->selection('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2')->render(), 'a developer is held to the workflow: no action is named in bulk');
        self::assertStringContainsString('data-live-property-param="assignee"', $render);
        self::assertStringContainsString('data-live-property-param="visibility"', $render);
        self::assertStringContainsString('data-controller="editor"', $render, 'the comment is written in the editor');

        $render = (string) $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-4', 'MD-SPT-6')->render();
        self::assertStringNotContainsString('MD-SPT-6', $render, 'an issue she may not see is left out');
        self::assertStringContainsString('data-live-property-param="criticality"', $render);
        self::assertStringContainsString('data-live-property-param="follow"', $render);
        self::assertStringNotContainsString('data-live-action-param="move"', $render, 'the step is for who moves freely to change');
        self::assertStringNotContainsString('data-live-property-param="assignee"', $render);
        self::assertStringNotContainsString('data-live-property-param="visibility"', $render);
    }

    public function testSeveralChangesAreAppliedTogether(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('move', ['tracker' => $this->scene->bugs->getId(), 'place' => 'qualified'])
            ->call('change', ['property' => 'assignee', 'value' => (string) $lea->getId()])
            ->call('change', ['property' => 'criticality', 'value' => 'high'])
            ->set('comment', 'Reproduced, to fix in the next batch.')
            ->call('apply');

        self::assertStringContainsString('2 issues updated', (string) $component->render());
        foreach (['MD-SPT-1', 'MD-SPT-2'] as $key) {
            $issue = $this->reloaded($key);
            self::assertSame('qualified', $issue->getPlace());
            self::assertSame('lea.fontaine@cairn.test', $issue->getAssignee()?->getEmail());
            self::assertSame(Criticality::High, $issue->getCriticality());
            $comments = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findBy(['issue' => $issue]);
            self::assertCount(1, $comments, 'one comment on each issue');
            self::assertSame('Reproduced, to fix in the next batch.', $comments[0]->getBody());
        }
    }

    public function testSeveralIssuesAreGivenToATeamOrToAPersonTheLastChoiceWins(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $hosting = $commands->dispatch(new CreateTeam('Hosting', true));
        $support = $commands->dispatch(new CreateTeam('Support', true));
        self::assertInstanceOf(Team::class, $hosting);
        self::assertInstanceOf(Team::class, $support);
        $commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $lea = $this->user('lea.fontaine@cairn.test');
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        $render = (string) $component->render();
        self::assertStringContainsString('data-live-property-param="assigneeTeam" data-live-value-param="'.$hosting->getId().'"', $render);
        self::assertStringNotContainsString('data-live-property-param="assigneeTeam" data-live-value-param="'.$support->getId().'"', $render, 'no role on the project');

        $render = (string) $component
            ->call('change', ['property' => 'assignee', 'value' => (string) $lea->getId()])
            ->call('change', ['property' => 'assigneeTeam', 'value' => (string) $support->getId()])
            ->call('change', ['property' => 'assigneeTeam', 'value' => (string) $hosting->getId()])
            ->render();
        self::assertStringContainsString('title="Hosting"', $render, 'the team chosen shows, and where each issue goes');
        $component->call('apply');

        self::assertStringContainsString('2 issues updated', (string) $component->render());
        foreach (['MD-SPT-1', 'MD-SPT-2'] as $key) {
            self::assertSame('Hosting', $this->reloaded($key)->getAssigneeTeam()?->getName());
            self::assertNull($this->reloaded($key)->getAssignee(), 'the person chosen first was forgotten');
        }

        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2');
        $component->call('change', ['property' => 'assigneeTeam', 'value' => (string) $hosting->getId()])
            ->call('change', ['property' => 'assignee', 'value' => (string) $lea->getId()])
            ->call('apply');
        self::assertSame('lea.fontaine@cairn.test', $this->reloaded('MD-SPT-1')->getAssignee()?->getEmail());
        self::assertNull($this->reloaded('MD-SPT-1')->getAssigneeTeam());
    }

    public function testEachIssueReachesTheStepInItsTrackerOrChangesCategory(): void
    {
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-4', 'MD-SPT-7');

        $component->call('move', ['tracker' => $this->scene->bugs->getId(), 'place' => 'qualified']);
        $render = (string) $component->render();
        self::assertStringNotContainsString('Qualify', $render, 'no action is named: the step is taken freely');
        self::assertSame(1, substr_count($render, 'changes category'), 'a feature becomes a bug, the two bugs only move');

        $component->call('apply');

        foreach (['MD-SPT-1', 'MD-SPT-4', 'MD-SPT-7'] as $key) {
            $issue = $this->reloaded($key);
            self::assertSame('Bug', $issue->getTracker()->getName(), $key);
            self::assertSame('qualified', $issue->getPlace(), $key);
        }
    }

    public function testAStepTheIssuesShareIsTakenByEachInItsOwnTracker(): void
    {
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-3', 'MD-SPT-6', 'MD-SPT-10');

        $render = (string) $component->render();
        self::assertSame(1, substr_count($render, 'data-live-action-param="move" data-live-place-param="to_test"'), 'a bug, a task and a feature all have "To test": offered once, without a tracker');
        self::assertStringNotContainsString('data-live-action-param="move" data-live-place-param="qualified"', $render, 'a place only the bugs have is behind "Change category"');
        self::assertStringContainsString('Change category…', $render);

        $render = (string) $component->call('move', ['place' => 'to_test'])->render();
        self::assertStringNotContainsString('Deliver for testing', $render, 'no action is named');
        self::assertStringNotContainsString('changes category', $render);

        $component->call('apply');

        foreach (['MD-SPT-1' => 'Bug', 'MD-SPT-3' => 'Bug', 'MD-SPT-6' => 'Internal task', 'MD-SPT-10' => 'Feature'] as $key => $tracker) {
            $issue = $this->reloaded($key);
            self::assertSame($tracker, $issue->getTracker()->getName(), $key.' stays in its tracker');
            self::assertSame('to_test', $issue->getPlace(), $key);
        }
    }

    public function testAStepTheIssuesDoNotShareIsNotTakenWithoutATracker(): void
    {
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-6');

        $component->call('move', ['place' => 'qualified'])->call('change', ['property' => 'criticality', 'value' => 'high'])->call('apply');

        self::assertSame('new', $this->reloaded('MD-SPT-1')->getPlace(), 'tasks have no "Qualified": the choice is checked against what is offered');
        self::assertSame('in_progress', $this->reloaded('MD-SPT-6')->getPlace());
    }

    public function testIssuesOfOneTrackerAreOfferedTheOtherTrackersBehindChangeCategory(): void
    {
        $render = (string) $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-2')->render();

        self::assertStringContainsString('data-live-action-param="move" data-live-place-param="qualified"', $render, 'two bugs: every place of their workflow, without a tracker');
        self::assertStringNotContainsString('data-live-tracker-param="'.$this->scene->bugs->getId().'"', $render, 'their own tracker is not a change of category');
        self::assertStringContainsString('data-live-tracker-param="'.$this->scene->tasks->getId().'"', $render);
    }

    public function testAListAcrossProjectsChangesNoStep(): void
    {
        $component = $this->selectionOf('thomas.girard@cairn.test', false, 'MD-SPT-1', 'MD-SPT-2');
        self::assertStringNotContainsString('data-live-action-param="move"', (string) $component->render());

        $component->call('move', ['tracker' => $this->scene->bugs->getId(), 'place' => 'qualified'])->call('apply');

        self::assertSame('new', $this->reloaded('MD-SPT-1')->getPlace());
    }

    public function testAnIssueIsRemovedFromTheSelection(): void
    {
        $component = $this->selection('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('remove', ['id' => $this->reloaded('MD-SPT-2')->getId()])
            ->call('change', ['property' => 'criticality', 'value' => 'high'])
            ->call('apply');

        self::assertStringNotContainsString('MD-SPT-2', (string) $component->render());
        self::assertSame(Criticality::High, $this->reloaded('MD-SPT-1')->getCriticality());
        self::assertNotSame(Criticality::High, $this->reloaded('MD-SPT-2')->getCriticality());
    }

    public function testADeveloperHidesAndFollowsSeveralIssues(): void
    {
        $component = $this->selection('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('change', ['property' => 'visibility', 'value' => 'private'])
            ->call('change', ['property' => 'follow', 'value' => 'follow'])
            ->call('apply');

        $subscriptions = self::getContainer()->get(IssueSubscriptionRepository::class);
        foreach (['MD-SPT-1', 'MD-SPT-2'] as $key) {
            $issue = $this->reloaded($key);
            self::assertTrue($issue->isPrivate(), $key);
            self::assertNotNull($subscriptions->findOne($issue, $this->user('nadia.benali@cairn.test')), $key);
        }
    }

    public function testUnfollowingHoldsEvenWithAComment(): void
    {
        $nadia = $this->user('nadia.benali@cairn.test');
        $component = $this->selection('nadia.benali@cairn.test', 'MD-SPT-1');

        $component->call('change', ['property' => 'follow', 'value' => 'unfollow'])
            ->set('comment', 'Handed over to the support team.')
            ->call('apply');

        self::assertNull(self::getContainer()->get(IssueSubscriptionRepository::class)->findOne($this->reloaded('MD-SPT-1'), $nadia), 'commenting subscribes, unfollowing comes after');
    }

    public function testApplyingTwiceCommentsOnce(): void
    {
        $component = $this->selection('nadia.benali@cairn.test', 'MD-SPT-1');

        $component->set('comment', 'Reproduced.')->call('apply')->call('apply');

        self::assertCount(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findBy(['issue' => $this->reloaded('MD-SPT-1')]));
    }

    public function testAValueThatMeansNothingIsIgnored(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1');
        $component->call('change', ['property' => 'assignee', 'value' => (string) $lea->getId()])->call('apply')->call('retry');
        self::assertSame('lea.fontaine@cairn.test', $this->reloaded('MD-SPT-1')->getAssignee()?->getEmail());

        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1');
        $component->call('change', ['property' => 'assignee', 'value' => 'abc'])
            ->call('change', ['property' => 'visibility', 'value' => 'whatever'])
            ->call('change', ['property' => 'follow', 'value' => 'whatever'])
            ->call('apply');

        self::assertStringContainsString('Choose at least one change', (string) $component->render());
        self::assertSame('lea.fontaine@cairn.test', $this->reloaded('MD-SPT-1')->getAssignee()?->getEmail(), 'not read as "nobody"');
    }

    public function testAClientSetsTheCriticalityOfSeveralIssues(): void
    {
        $component = $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('change', ['property' => 'criticality', 'value' => 'high'])->call('apply');

        self::assertSame(Criticality::High, $this->reloaded('MD-SPT-1')->getCriticality());
        self::assertSame(Criticality::High, $this->reloaded('MD-SPT-2')->getCriticality());
    }

    public function testADeveloperSchedulesSeveralIssuesInARelease(): void
    {
        $release = $this->scene->release();
        $intranetBug = $this->scene->intranetBug();
        self::assertStringNotContainsString('data-live-property-param="release"', (string) $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2')->render(), 'a client does not schedule');
        self::assertStringNotContainsString('data-live-property-param="release"', (string) $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', $intranetBug->getKey())->render(), 'a release belongs to one project');

        $component = $this->selection('nadia.benali@cairn.test', 'MD-SPT-1', 'MD-SPT-2');
        $component->call('change', ['property' => 'release', 'value' => (string) $release->getId()])->call('apply');

        self::assertSame('2.5.0', $this->reloaded('MD-SPT-1')->getRelease()?->getName());
        self::assertSame('2.5.0', $this->reloaded('MD-SPT-2')->getRelease()?->getName());
    }

    public function testATagIsAddedToSeveralIssuesBesideThoseTheyCarry(): void
    {
        $front = $this->scene->tag('Front', keys: ['MD-SPT-1']);
        $back = $this->scene->tag('Back office');
        $intranetBug = $this->scene->intranetBug();
        self::assertStringNotContainsString('data-live-property-param="tag"', (string) $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', $intranetBug->getKey())->render(), 'a tag belongs to one project');

        $component = $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2');
        $component->call('change', ['property' => 'tag', 'value' => (string) $back->getId()]);
        self::assertStringContainsString('Back office</span></span>', (string) $component->call('change', ['property' => 'tag', 'value' => '00'])->render(), 'no tag is "none": the choice made stays');
        $component->call('apply');

        $tags = self::getContainer()->get(IssueTagRepository::class);
        $names = fn (string $key): array => array_map(static fn (Tag $tag): string => $tag->getName(), $tags->tagsOf($this->reloaded($key)));
        self::assertSame(['Back office', 'Front'], $names('MD-SPT-1'), 'the tag it carried stays');
        self::assertSame(['Back office'], $names('MD-SPT-2'));
    }

    public function testARefusalStopsItsIssueAndLeavesTheOthersDone(): void
    {
        $component = $this->selection('thomas.girard@cairn.test', 'MD-SPT-1', 'MD-SPT-6');

        $component->call('change', ['property' => 'visibility', 'value' => 'public'])
            ->set('comment', 'Visible to the client from now on.')
            ->call('apply');

        $render = (string) $component->render();
        self::assertStringContainsString('1 issue updated', $render);
        self::assertStringContainsString('Visibility refused', $render, 'the line of the internal task says what was refused');
        $comments = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class);
        self::assertCount(1, $comments->findBy(['issue' => $this->reloaded('MD-SPT-1')]));
        self::assertCount(0, $comments->findBy(['issue' => $this->reloaded('MD-SPT-6')]), 'nothing is tried after a refusal');

        $component->call('retry');
        $render = (string) $component->render();
        self::assertStringContainsString('MD-SPT-6', $render);
        self::assertStringNotContainsString('MD-SPT-1', $render, 'only the refused issue is taken again');
        self::assertStringContainsString('data-live-action-param="apply"', $render, 'with the changes as they were');
    }

    public function testAChangeTheSelectionDoesNotOfferIsIgnored(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $component = $this->selection('claire.martin@maison-dupont.test', 'MD-SPT-1', 'MD-SPT-2');

        $component->call('change', ['property' => 'assignee', 'value' => (string) $lea->getId()])
            ->call('move', ['tracker' => $this->scene->bugs->getId(), 'place' => 'qualified'])
            ->call('apply');

        self::assertStringContainsString('Choose at least one change', (string) $component->render());
        self::assertSame('new', $this->reloaded('MD-SPT-1')->getPlace(), 'the browser\'s choice is checked against what is offered');
        self::assertNull($this->reloaded('MD-SPT-1')->getAssignee());
    }

    private function selection(string $email, string ...$keys): TestLiveComponent
    {
        return $this->selectionOf($email, true, ...$keys);
    }

    private function selectionOf(string $email, bool $inProject, string ...$keys): TestLiveComponent
    {
        $ids = array_map(fn (string $key): int => (int) $this->reloaded($key)->getId(), array_values($keys));

        return $this->createLiveComponent('IssueList:Selection', ['issueIds' => $ids, 'back' => self::FROM, 'inProject' => $inProject])
            ->actingAs($this->user($email));
    }

    private function reloaded(string $key): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Issue::class)->findOneBy(['key' => $key]) ?? throw new \LogicException(\sprintf('No %s.', $key));
    }

    private function user(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager->getRepository(User::class)->findOneBy(['email' => $email]) ?? throw new \LogicException(\sprintf('No %s.', $email));
    }
}
