<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Team;

use App\Entity\Enum\JournalEntryType;
use App\Entity\Issue;
use App\Entity\IssueTeamSubscription;
use App\Entity\JournalEntry;
use App\Entity\PendingNotification;
use App\Entity\Team;
use App\Entity\User;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\UnfollowIssueAsTeam;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\DeleteTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\Command\Team\RemoveTeamMember;
use App\Message\CommandBus;
use App\Message\Notification\NotifyIssueChange;
use App\Message\Query\Issue\IssueGrouping;
use App\Message\Query\Issue\SearchIssues;
use App\MessageHandler\Command\Issue\IssueException;
use App\MessageHandler\Command\Team\TeamException;
use App\MessageHandler\Notification\NotifyIssueChangeHandler;
use App\Repository\PendingNotificationRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * An issue given to a team instead of a person. In the fixtures Thomas
 * manages Maison Dupont, Nadia develops there, Claire is its client; the
 * hosting team (Marc, Léa) holds a role on the workspace.
 */
final class TeamAssignmentTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $fixtures;
    private Team $hosting;
    private User $marc;
    private User $lea;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new IssueFixtures($this->entityManager, $this->commands);
        $this->marc = $this->fixtures->access->user('marc@agency.test', internal: true);
        $this->lea = $this->fixtures->access->user('lea@agency.test', internal: true);
        $this->hosting = $this->team('Hosting', true, $this->marc, $this->lea);
        $this->commands->dispatch(new GrantTeamAccess((int) $this->hosting->getId(), $this->fixtures->access->workspace->getId(), null, 'developer'));
    }

    public function testAnIssueIsGivenToATeamOrToAPersonNeverBoth(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();
        $key = $issue->getKey();

        $this->commands->dispatch(new UpdateIssue($manager, $key, assigneeId: $this->fixtures->developer->getId()));
        $this->commands->dispatch(new UpdateIssue($manager, $key, assigneeTeamId: $this->hosting->getId()));
        self::assertSame($this->hosting, $issue->getAssigneeTeam());
        self::assertNull($issue->getAssignee(), 'the person no longer holds it');
        self::assertSame(['field' => 'assignee', 'from' => 'Nadia Test', 'to' => 'Hosting'], $this->lastChange($issue)->getData());

        $entries = $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $issue]);
        $this->commands->dispatch(new UpdateIssue($manager, $key, assigneeTeamId: $this->hosting->getId()));
        self::assertSame($entries, $this->entityManager->getRepository(JournalEntry::class)->count(['issue' => $issue]), 'nothing changed, nothing to tell');

        $this->commands->dispatch(new UpdateIssue((int) $this->marc->getId(), $key, assigneeId: $this->marc->getId()));
        self::assertNull($issue->getAssigneeTeam(), 'whoever takes it takes it from the team');
        self::assertSame($this->marc, $issue->getAssignee());
        self::assertSame(['field' => 'assignee', 'from' => 'Hosting', 'to' => 'Marc Test'], $this->lastChange($issue)->getData());

        $this->commands->dispatch(new UpdateIssue($manager, $key, assigneeTeamId: $this->hosting->getId()));
        $this->commands->dispatch(new UpdateIssue($manager, $key, assigneeId: UpdateIssue::NOBODY));
        self::assertNull($issue->getAssigneeTeam(), 'nobody is neither a person nor a team');
        self::assertSame(['field' => 'assignee', 'from' => 'Hosting', 'to' => null], $this->lastChange($issue)->getData());

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new UpdateIssue($manager, $key, assigneeId: $this->marc->getId(), assigneeTeamId: $this->hosting->getId()));
    }

    public function testOnlyATeamThatHoldsARoleOnTheProjectIsAssignedByWhoeverMayAssign(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $support = $this->team('Support', true, $this->marc);

        try {
            $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $issue->getKey(), assigneeTeamId: $support->getId()));
            self::fail('A team without a role on the project was assigned.');
        } catch (IssueException $e) {
            self::assertStringContainsString('holds no role', $e->getMessage());
        }

        $this->expectException(AccessDeniedException::class);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->client->getId(), $issue->getKey(), assigneeTeamId: $this->hosting->getId()));
    }

    public function testAnIssueIsCreatedForATeam(): void
    {
        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->manager->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'Redirect the old domain',
            fields: ['environment' => 'prod'],
            assigneeTeamId: $this->hosting->getId(),
        ));

        self::assertInstanceOf(Issue::class, $issue);
        self::assertSame($this->hosting, $issue->getAssigneeTeam());
        self::assertSame('Hosting', $issue->getAssigneeName());
    }

    public function testATeamGivenAnIssueFollowsItAsOneUntilAMemberUnsubscribesIt(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeTeamId: $this->hosting->getId()));
        self::assertSame(['claire@client.test', 'lea@agency.test', 'marc@agency.test'], $this->toldOfLast(), 'the team learns it was given the issue; Thomas acted');

        // The team follows, not its members: who is part of it is read when the change leaves.
        $yann = $this->fixtures->access->user('yann@agency.test', internal: true);
        $this->commands->dispatch(new AddTeamMember((int) $this->hosting->getId(), (int) $yann->getId()));
        $this->commands->dispatch(new RemoveTeamMember((int) $this->hosting->getId(), (int) $this->lea->getId()));
        $this->commands->dispatch(new AddComment($manager, $issue->getKey(), 'Between us.', private: true));
        self::assertSame(['marc@agency.test', 'yann@agency.test'], $this->toldOfLast(), 'whoever joined is told, whoever left is not, and the client reads nothing private');

        $this->commands->dispatch(new AddComment((int) $this->marc->getId(), $issue->getKey(), 'Done.'));
        $this->commands->dispatch(new UpdateIssue((int) $this->marc->getId(), $issue->getKey(), assigneeId: $manager));
        $this->commands->dispatch(new AddComment($manager, $issue->getKey(), 'Thanks.'));
        self::assertSame(['claire@client.test', 'marc@agency.test', 'yann@agency.test'], $this->toldOfLast(), 'the team keeps following what it was given, as a person does');

        try {
            $this->commands->dispatch(new UnfollowIssueAsTeam((int) $this->fixtures->developer->getId(), $issue->getKey(), (int) $this->hosting->getId()));
            self::fail('Someone outside the team unsubscribed it.');
        } catch (AccessDeniedException) {
        }
        $this->commands->dispatch(new UnfollowIssueAsTeam((int) $yann->getId(), $issue->getKey(), (int) $this->hosting->getId()));
        $this->commands->dispatch(new UnfollowIssueAsTeam((int) $yann->getId(), $issue->getKey(), (int) $this->hosting->getId()));
        $this->commands->dispatch(new AddComment($manager, $issue->getKey(), 'One more thing.'));
        self::assertSame(['claire@client.test', 'marc@agency.test'], $this->toldOfLast(), 'any member unsubscribes the team; Marc follows because he took part');

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeTeamId: $this->hosting->getId()));
        self::assertSame(['claire@client.test', 'marc@agency.test', 'yann@agency.test'], $this->toldOfLast(), 'given the issue again, the team follows it again');
    }

    public function testTheListsFilterAndGroupByTeam(): void
    {
        $manager = (int) $this->fixtures->manager->getId();
        $forTeam = $this->fixtures->createIssue($this->fixtures->client, title: 'For the team');
        $forNadia = $this->fixtures->createIssue($this->fixtures->client, title: 'For Nadia');
        $forNobody = $this->fixtures->createIssue($this->fixtures->client, title: 'For nobody');
        $this->commands->dispatch(new UpdateIssue($manager, $forTeam->getKey(), assigneeTeamId: $this->hosting->getId()));
        $this->commands->dispatch(new UpdateIssue($manager, $forNadia->getKey(), assigneeId: $this->fixtures->developer->getId()));
        $viewer = $this->fixtures->manager;

        self::assertSame(['For the team'], $this->titles(new SearchIssues($viewer, team: [(int) $this->hosting->getId()])));
        self::assertSame(['For nobody'], $this->titles(new SearchIssues($viewer, assignee: [0])), 'given to a team is not unassigned');
        self::assertSame(['For Nadia', 'For the team'], $this->titles(new SearchIssues($viewer, assignee: [(int) $this->fixtures->developer->getId()], team: [(int) $this->hosting->getId()])), 'a person or a team: one dimension');
        self::assertSame(['For the team'], $this->titles(new SearchIssues($viewer, teamMemberId: $this->marc->getId())), 'the issues of my teams');
        self::assertSame([], $this->titles(new SearchIssues($viewer, teamMemberId: $this->fixtures->developer->getId())));

        $grouped = self::queryBus()->ask(new SearchIssues($viewer, group: IssueGrouping::Assignee));
        self::assertSame(['For nobody', 'For Nadia', 'For the team'], array_map(static fn (Issue $issue): string => $issue->getTitle(), $grouped->items), 'nobody, then people, then teams');
        $keys = array_map(IssueGrouping::Assignee->keyOf(...), $grouped->items);
        self::assertSame(['none', (string) $this->fixtures->developer->getId(), 'team-'.$this->hosting->getId()], $keys);
        self::assertEquals(array_fill_keys($keys, 1), $grouped->groupTotals, 'the SQL names the groups as the PHP does');
    }

    public function testATeamStaysWhileIssuesAreAssignedToIt(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $manager = (int) $this->fixtures->manager->getId();
        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeTeamId: $this->hosting->getId()));

        try {
            $this->commands->dispatch(new DeleteTeam((int) $this->hosting->getId()));
            self::fail('A team that holds an issue was deleted.');
        } catch (TeamException $e) {
            self::assertStringContainsString('1 issue(s) are assigned', $e->getMessage());
        }

        $this->commands->dispatch(new UpdateIssue($manager, $issue->getKey(), assigneeId: UpdateIssue::NOBODY));
        $this->commands->dispatch(new DeleteTeam((int) $this->hosting->getId()));
        self::assertSame(0, $this->entityManager->getRepository(Team::class)->count([]));
        self::assertSame(0, $this->entityManager->getRepository(IssueTeamSubscription::class)->count([]), 'what it followed went with it');
    }

    private function team(string $name, bool $internal, User ...$members): Team
    {
        $team = $this->commands->dispatch(new CreateTeam($name, $internal));
        self::assertInstanceOf(Team::class, $team);
        foreach ($members as $member) {
            $this->commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $member->getId()));
        }

        return $team;
    }

    private function lastChange(Issue $issue): JournalEntry
    {
        $entry = $this->entityManager->getRepository(JournalEntry::class)->findOneBy(['issue' => $issue, 'type' => JournalEntryType::Changed], ['id' => 'DESC']);
        self::assertNotNull($entry);

        return $entry;
    }

    /**
     * Who the last change waits for, by address: the handler is handed the
     * last message a command left on the in-memory transport.
     *
     * @return list<string>
     */
    private function toldOfLast(): array
    {
        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $sent = $async->getSent();
        $envelope = end($sent);
        self::assertInstanceOf(Envelope::class, $envelope);
        $message = $envelope->getMessage();
        self::assertInstanceOf(NotifyIssueChange::class, $message);

        $pending = self::getContainer()->get(PendingNotificationRepository::class);
        $before = array_map(static fn (PendingNotification $row): ?int => $row->getId(), $pending->findWaiting());
        self::getContainer()->get(NotifyIssueChangeHandler::class)($message);
        $emails = [];
        foreach ($pending->findWaiting() as $row) {
            if (!\in_array($row->getId(), $before, true)) {
                $emails[] = $row->getRecipient()->getEmail();
            }
        }
        sort($emails);

        return $emails;
    }

    /**
     * @return list<string>
     */
    private function titles(SearchIssues $query): array
    {
        $titles = array_map(static fn (Issue $issue): string => $issue->getTitle(), self::queryBus()->ask($query)->items);
        sort($titles);

        return $titles;
    }
}
