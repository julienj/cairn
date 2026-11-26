<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\Issue;
use App\Entity\PendingNotification;
use App\Entity\Team;
use App\Entity\User;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\FollowIssue;
use App\Message\Command\Membership\GrantMembership;
use App\Message\Command\Membership\RevokeMembership;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\Command\Team\RevokeTeamAccess;
use App\Message\CommandBus;
use App\Message\Notification\NotifyIssueChange;
use App\Message\Query\Issue\ListSubscribers;
use App\MessageHandler\Notification\NotifyIssueChangeHandler;
use App\Repository\AutomationRunRepository;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\MembershipRepository;
use App\Repository\PendingNotificationRepository;
use App\Repository\TeamAccessRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Following an issue takes a role on its project. Thomas manages Maison
 * Dupont, Nadia develops there, Claire is its client; Léa is an internal
 * user without a role, Julie an administrator without one.
 */
final class FollowingNeedsARoleTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private Issue $issue;
    private User $lea;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $this->issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->lea = $this->fixtures->access->user('lea@agency.test', internal: true);
    }

    public function testAnInternalUserWithoutARoleReadsTheIssueAndDoesNotFollowIt(): void
    {
        try {
            $this->commands->dispatch(new FollowIssue((int) $this->lea->getId(), $this->issue->getKey()));
            self::fail('Someone without a role on the project followed one of its issues.');
        } catch (AccessDeniedException) {
        }
        self::assertFalse($this->subscribed($this->lea));

        $this->fixtures->access->grantOnProject($this->lea, $this->fixtures->access->shop, 'developer');
        $this->commands->dispatch(new FollowIssue((int) $this->lea->getId(), $this->issue->getKey()));
        self::assertTrue($this->subscribed($this->lea));
    }

    public function testAnAdministratorActsEverywhereAndFollowsOnlyWhereTheyHoldARole(): void
    {
        $julie = $this->fixtures->access->user('julie@agency.test', internal: true, admin: true);

        $this->commands->dispatch(new AddComment((int) $julie->getId(), $this->issue->getKey(), 'I had a look.'));
        $created = $this->commands->dispatch(new CreateIssue((int) $julie->getId(), (int) $this->fixtures->access->shop->getId(), (int) $this->fixtures->bugs->getId(), 'Seen from the administration', fields: ['environment' => 'prod']));
        self::assertInstanceOf(Issue::class, $created);

        $subscriptions = self::getContainer()->get(IssueSubscriptionRepository::class);
        self::assertNull($subscriptions->findOne($this->issue, $julie), 'commenting does not subscribe who holds no role');
        self::assertNull($subscriptions->findOne($created, $julie), 'nor does writing the issue');
    }

    public function testWhoeverLosesTheirRoleIsNoLongerWrittenToAndIsAgainWithOne(): void
    {
        $membership = $this->fixtures->access->grantOnProject($this->lea, $this->fixtures->access->shop, 'developer');
        $this->commands->dispatch(new FollowIssue((int) $this->lea->getId(), $this->issue->getKey()));
        self::assertContains('lea@agency.test', $this->told('First.'));
        self::assertContains($this->lea, self::queryBus()->ask(new ListSubscribers($this->issue)));

        $this->commands->dispatch(new RevokeMembership((int) $membership->getId()));
        self::assertNotContains('lea@agency.test', $this->told('Second.'), 'she reads the project still, and is not written to');
        self::assertNotContains($this->lea, self::queryBus()->ask(new ListSubscribers($this->issue)), 'nor listed among the followers');
        self::assertTrue($this->subscribed($this->lea), 'what she followed is kept, asleep');

        $this->commands->dispatch(new GrantMembership((int) $this->lea->getId(), null, $this->fixtures->access->shop->getId(), 'developer'));
        self::assertContains('lea@agency.test', $this->told('Third.'), 'back on the project, she follows what she followed');
    }

    public function testATeamFollowsOnlyWhileItHoldsARoleOnTheProject(): void
    {
        $marc = $this->fixtures->access->user('marc@agency.test', internal: true);
        $hosting = $this->commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(Team::class, $hosting);
        $this->commands->dispatch(new AddTeamMember((int) $hosting->getId(), (int) $marc->getId()));
        $this->commands->dispatch(new AddTeamMember((int) $hosting->getId(), (int) $this->fixtures->developer->getId()));
        $grant = new GrantTeamAccess((int) $hosting->getId(), $this->fixtures->access->workspace->getId(), null, 'developer');
        $this->commands->dispatch($grant);
        $this->commands->dispatch(new AddComment((int) $this->fixtures->manager->getId(), $this->issue->getKey(), \sprintf('For @[Hosting](team:%d).', $hosting->getId())));
        $teams = self::getContainer()->get(IssueTeamSubscriptionRepository::class);
        self::assertSame([$hosting], $teams->findTeams($this->issue));
        self::assertSame([$hosting], $teams->findTeamsOf($this->issue, $marc));
        self::assertContains('marc@agency.test', $this->told('First.'));

        $access = self::getContainer()->get(TeamAccessRepository::class)->findOne($hosting, $this->fixtures->access->workspace);
        $this->commands->dispatch(new RevokeTeamAccess((int) $access?->getId()));
        self::assertSame([], $teams->findTeams($this->issue), 'a team without a role on the project is not among the followers');
        self::assertSame([], $teams->findTeamsOf($this->issue, $this->fixtures->developer), 'not even for a member who holds a role of their own');
        $told = $this->told('Second.');
        self::assertNotContains('marc@agency.test', $told);
        self::assertNotContains('nadia@agency.test', $told, 'Nadia works on the project and never followed this issue herself');

        $this->commands->dispatch($grant);
        self::assertSame([$hosting], $teams->findTeams($this->issue), 'with a role again, the team follows what it followed');
        self::assertContains('marc@agency.test', $this->told('Third.'));
    }

    public function testARuleMakesNobodyFollowAProjectTheyHoldNoRoleOn(): void
    {
        $rule = $this->commands->dispatch(new CreateRule((int) $this->fixtures->access->shop->getId(), 'Léa follows new issues', null, [
            'trigger' => ['type' => 'issue_created', 'params' => []],
            'conditions' => [],
            'actions' => [['type' => 'subscribe', 'params' => ['target' => 'user:'.$this->fixtures->developer->getId()]]],
        ]));
        $membership = self::getContainer()->get(MembershipRepository::class)->findByUser($this->fixtures->developer)[0];
        $this->commands->dispatch(new RevokeMembership((int) $membership->getId()));

        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertNull(self::getContainer()->get(IssueSubscriptionRepository::class)->findOne($issue, $this->fixtures->developer));
        $runs = self::getContainer()->get(AutomationRunRepository::class)->findBy(['rule' => $rule]);
        self::assertSame([['action' => 'subscribe', 'result' => 'skipped', 'value' => 'Nadia Test', 'reason' => 'not_a_member']], $runs[0]->getSteps());
    }

    private function subscribed(User $user): bool
    {
        return null !== self::getContainer()->get(IssueSubscriptionRepository::class)->findOne($this->issue, $user);
    }

    /**
     * Who a comment of the manager waits for, by address: the handler is
     * handed the message the command left on the in-memory transport.
     *
     * @return list<string>
     */
    private function told(string $comment): array
    {
        $this->commands->dispatch(new AddComment((int) $this->fixtures->manager->getId(), $this->issue->getKey(), $comment));
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

        return array_values(array_map(
            static fn (PendingNotification $row): string => $row->getRecipient()->getEmail(),
            array_filter($pending->findWaiting(), static fn (PendingNotification $row): bool => !\in_array($row->getId(), $before, true)),
        ));
    }
}
