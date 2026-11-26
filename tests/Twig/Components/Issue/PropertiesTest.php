<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Enum\TagColour;
use App\Entity\Issue;
use App\Entity\JournalEntry;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\User;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Repository\IssueTagRepository;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * On MD-SPT-4 of the scene, a "Bug" on "To test": Thomas manages the
 * Maison Dupont workspace, and his role moves issues freely: any place,
 * any tracker, no action. Nadia, developer, and Claire, the client, are
 * held to the workflow: the step is not a menu for them. Sophie is a
 * client of another workspace.
 */
final class PropertiesTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->issue = $this->scene->issue('MD-SPT-4');
    }

    public function testEachPropertyIsAChoiceForWhoeverMayChangeIt(): void
    {
        $manager = (string) $this->properties('thomas.girard@cairn.test')->render();
        self::assertStringContainsString('data-live-property-param="assignee"', $manager);
        self::assertStringContainsString('data-live-property-param="criticality"', $manager);
        self::assertStringContainsString('data-live-property-param="visibility"', $manager);

        $client = (string) $this->properties('claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('data-live-property-param="criticality"', $client);
        self::assertStringNotContainsString('data-live-property-param="assignee"', $client);
        self::assertStringNotContainsString('data-live-property-param="visibility"', $client);
        self::assertStringNotContainsString('Visibility', $client, 'not even shown to external users');
    }

    public function testAManagerAssignsTheIssue(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $component = $this->properties('thomas.girard@cairn.test');

        $component->call('change', ['property' => 'assignee', 'value' => (string) $lea->getId()]);
        $render = $component->render();

        self::assertSame(200, $component->response()->getStatusCode(), 'no redirect');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertNotNull($component->getDispatchedBrowserEvent($render, 'toast:show'));
        self::assertSame('lea.fontaine@cairn.test', $this->reloaded()->getAssignee()?->getEmail());
    }

    public function testAManagerGivesTheIssueToATeamThatHoldsARoleOnTheProject(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $hosting = $commands->dispatch(new CreateTeam('Hosting', true));
        $support = $commands->dispatch(new CreateTeam('Support', true));
        self::assertInstanceOf(Team::class, $hosting);
        self::assertInstanceOf(Team::class, $support);
        $commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $component = $this->properties('thomas.girard@cairn.test');

        $render = (string) $component->render();
        self::assertStringContainsString('data-live-property-param="assigneeTeam" data-live-value-param="'.$hosting->getId().'"', $render);
        self::assertStringNotContainsString('data-live-property-param="assigneeTeam" data-live-value-param="'.$support->getId().'"', $render, 'no role on the project');

        $rendered = $component->call('change', ['property' => 'assigneeTeam', 'value' => (string) $hosting->getId()])->render();
        $render = (string) $rendered;

        self::assertNotNull($component->getEmittedEvent($rendered, 'issue:changed'));
        self::assertSame('Hosting', $this->reloaded()->getAssigneeTeam()?->getName());
        self::assertNull($this->reloaded()->getAssignee());
        self::assertStringContainsString('title="Hosting"', $render, 'drawn as a team');

        $component->call('change', ['property' => 'assigneeTeam', 'value' => (string) $support->getId()]);
        self::assertSame('Hosting', $this->reloaded()->getAssigneeTeam()?->getName(), 'whatever the request says');
    }

    public function testTheMenuOffersTheMembersOfTheProjectAndNobody(): void
    {
        $lea = $this->user('lea.fontaine@cairn.test');
        $sophie = $this->user('sophie.bernard@travelia.test');
        $manager = (string) $this->properties('thomas.girard@cairn.test')->render();

        self::assertStringContainsString('data-live-value-param="'.$lea->getId().'"', $manager);
        self::assertStringNotContainsString('data-live-value-param="'.$sophie->getId().'"', $manager, 'not a member of the project');
        self::assertStringContainsString('data-live-property-param="assignee" data-live-value-param=""', $manager, 'nobody');
    }

    public function testThePanelFollowsAChangeMadeElsewhere(): void
    {
        $component = $this->properties('thomas.girard@cairn.test');
        $claire = $this->user('claire.martin@maison-dupont.test');
        self::getContainer()->get(CommandBus::class)->dispatch(new ApplyTransition((int) $claire->getId(), 'MD-SPT-4', 'accept'));

        $component->emit('issue:changed');

        self::assertStringContainsString('To release', (string) $component->render());
    }

    public function testTheReleaseShowsOnceTheProjectHasReleases(): void
    {
        self::assertStringNotContainsString('data-live-property-param="release"', (string) $this->properties('thomas.girard@cairn.test')->render());

        $release = $this->scene->release();
        self::assertStringNotContainsString('data-live-property-param="release"', (string) $this->properties('claire.martin@maison-dupont.test')->render(), 'a client reads it');
        $component = $this->properties('nadia.benali@cairn.test');
        self::assertStringContainsString('data-live-property-param="release"', (string) $component->render());

        $component->call('change', ['property' => 'release', 'value' => (string) $release->getId()]);
        self::assertSame('2.5.0', $this->reloaded()->getRelease()?->getName());
        self::assertStringContainsString('2.5.0', (string) $this->properties('claire.martin@maison-dupont.test')->render());
    }

    public function testTheTagsShowOnceTheProjectHasTagsAndAreTickedOneByOne(): void
    {
        self::assertStringNotContainsString('data-live-action-param="toggleTag"', (string) $this->properties('thomas.girard@cairn.test')->render());

        $back = $this->scene->tag('Back office');
        $front = $this->scene->tag('Front', TagColour::Rose);
        $tags = self::getContainer()->get(IssueTagRepository::class);
        $names = fn (): array => array_map(static fn (Tag $tag): string => $tag->getName(), $tags->tagsOf($this->reloaded()));

        $component = $this->properties('claire.martin@maison-dupont.test');
        self::assertStringContainsString(\sprintf('data-live-tag-param="%d"', $front->getId()), (string) $component->render(), 'a client tags too');

        $component->call('toggleTag', ['tag' => $back->getId()]);
        $render = $component->call('toggleTag', ['tag' => $front->getId()])->render();
        self::assertSame(['Back office', 'Front'], $names());
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'), 'the history of the page follows');

        $component->call('toggleTag', ['tag' => $back->getId()]);
        self::assertSame(['Front'], $names(), 'ticked again, the tag is taken off');
        self::assertSame(['field' => 'tag_removed', 'from' => 'Back office', 'to' => null], $this->lastEntry()?->getData());
    }

    public function testSomeoneOutsideTheProjectCannotBeAssigned(): void
    {
        $sophie = $this->user('sophie.bernard@travelia.test');
        $component = $this->properties('thomas.girard@cairn.test');

        $component->call('change', ['property' => 'assignee', 'value' => (string) $sophie->getId()]);

        self::assertStringContainsString('could not be applied', (string) $component->render());
        self::assertNotSame('sophie.bernard@travelia.test', $this->reloaded()->getAssignee()?->getEmail());
    }

    public function testTheStepIsAMenuForWhoMovesFreely(): void
    {
        $manager = (string) $this->properties('thomas.girard@cairn.test')->render();
        self::assertStringContainsString('data-live-action-param="move" data-live-place-param="qualified">', $manager);
        self::assertStringContainsString('data-live-action-param="move" data-live-place-param="to_release">', $manager, 'the client\'s "Accept" leads there: he moves all the same');
        self::assertStringNotContainsString('data-live-action-param="transition"', $manager, 'no action in the menu');
        self::assertStringNotContainsString('data-live-place-param="to_test">', $manager, 'the current place is not a move');
        self::assertStringContainsString('Change category…', $manager);
        self::assertStringContainsString(\sprintf('data-live-place-param="to_estimate" data-live-tracker-param="%d"', $this->scene->evolutions->getId()), $manager);
        self::assertStringContainsString('Internal task', $manager, 'private, but Thomas is internal');

        // Held to the workflow, internal or not: the step is read, the actions are in the response box.
        foreach (['nadia.benali@cairn.test', 'claire.martin@maison-dupont.test'] as $guided) {
            $render = (string) $this->properties($guided)->render();
            self::assertStringNotContainsString('data-controller="panels"', $render);
            self::assertStringNotContainsString('data-live-action-param="move"', $render);
            self::assertStringNotContainsString('Change category…', $render);
            self::assertStringContainsString('To test', $render);
        }
    }

    public function testAPlaceIsTakenWithoutAnActionEvenWhereOneLeads(): void
    {
        $component = $this->properties('thomas.girard@cairn.test');

        $component->call('move', ['place' => 'to_release']);
        $render = $component->render();

        self::assertSame('to_release', $this->reloaded()->getPlace());
        self::assertSame(JournalEntryType::Moved, $this->lastEntry()?->getType(), '"Accept" is the client\'s');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertSame(['message' => 'MD-SPT-4 moved to To release.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
    }

    public function testTheMenuMovesNothingForARoleHeldToTheWorkflow(): void
    {
        foreach (['nadia.benali@cairn.test', 'claire.martin@maison-dupont.test'] as $guided) {
            $component = $this->properties($guided);

            // "To release" is where the client's "Accept" leads: an action is taken from the response box, not here.
            $component->call('move', ['place' => 'to_release']);
            self::assertStringContainsString('could not be applied', (string) $component->render());
            self::assertSame('to_test', $this->reloaded()->getPlace());

            $component->call('move', ['place' => 'to_estimate', 'tracker' => $this->scene->evolutions->getId()]);
            self::assertSame('Bug', $this->reloaded()->getTracker()->getName());
        }
    }

    public function testTheTrackerChangesWithAPlaceOfTheNewWorkflow(): void
    {
        $component = $this->properties('thomas.girard@cairn.test');

        $component->call('move', ['place' => 'to_estimate', 'tracker' => $this->scene->evolutions->getId()]);

        $issue = $this->reloaded();
        self::assertSame('Feature', $issue->getTracker()->getName());
        self::assertSame('to_estimate', $issue->getPlace());
        self::assertSame(['message' => 'MD-SPT-4 moved to To estimate.'], $component->getDispatchedBrowserEvent($component->render(), 'toast:show')['payload'] ?? null);
    }

    public function testAnArchivedProjectOffersNoMove(): void
    {
        $this->scene->shop->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $render = (string) $this->properties('thomas.girard@cairn.test')->render();
        self::assertStringNotContainsString('data-controller="panels"', $render);
        self::assertStringContainsString('To test', $render);
    }

    private function lastEntry(): ?JournalEntry
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(JournalEntry::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
    }

    private function properties(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Properties', ['issue' => $this->issue])->actingAs($this->user($email));
    }

    private function user(string $email): User
    {
        return $this->scene->user($email);
    }

    private function reloaded(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Issue::class, $this->issue->getId()) ?? self::fail('issue vanished');
    }
}
