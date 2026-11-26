<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\Issue;
use App\Entity\Team;
use App\Entity\User;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\ApplyTransition;
use App\Message\Command\Issue\CreateIssue;
use App\Message\Command\Issue\UnfollowIssue;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who follows an issue after each command, through the real commands: the
 * rules listen to the domain events. In the test configuration the
 * project manager role follows new issues; Thomas holds it on the
 * workspace, Nadia develops, Claire is the client.
 */
final class SubscriptionRulesTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private IssueSubscriptionRepository $subscriptions;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $this->subscriptions = self::getContainer()->get(IssueSubscriptionRepository::class);
    }

    public function testTheAuthorAndTheRolesThatFollowNewIssuesAreSubscribedAtCreation(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        self::assertSame(['claire@client.test', 'thomas@agency.test'], $this->subscribers($issue));
    }

    public function testTheAssigneeIsSubscribedWhenAssigned(): void
    {
        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->manager->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'Empty CSV export',
            description: 'The file is empty.',
            fields: ['environment' => 'prod'],
            assigneeId: (int) $this->fixtures->developer->getId(),
        ));
        self::assertSame(['nadia@agency.test', 'thomas@agency.test'], $this->subscribers($issue), 'the assignee at creation');

        $other = $this->fixtures->createIssue($this->fixtures->client);
        $this->commands->dispatch(new UpdateIssue((int) $this->fixtures->manager->getId(), $other->getKey(), assigneeId: (int) $this->fixtures->developer->getId()));
        self::assertSame(['claire@client.test', 'nadia@agency.test', 'thomas@agency.test'], $this->subscribers($other), 'the assignee on reassignment');
    }

    public function testWhoeverCommentsOrIsMentionedFollows(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $lea = $this->fixtures->access->user('lea@agency.test', internal: true);
        $this->fixtures->access->grantOnProject($lea, $this->fixtures->access->shop, 'developer');

        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), \sprintf('Looking into it with @[Léa](user:%d).', $lea->getId())));

        self::assertSame(['claire@client.test', 'lea@agency.test', 'nadia@agency.test', 'thomas@agency.test'], $this->subscribers($issue));
    }

    public function testATeamMentionedInATextFollowsTheIssueAsOne(): void
    {
        $marc = $this->fixtures->access->user('marc@agency.test', internal: true);
        $hosting = $this->commands->dispatch(new CreateTeam('Hosting', true));
        $support = $this->commands->dispatch(new CreateTeam('Support', true));
        self::assertInstanceOf(Team::class, $hosting);
        self::assertInstanceOf(Team::class, $support);
        $this->commands->dispatch(new AddTeamMember((int) $hosting->getId(), (int) $marc->getId()));
        $this->commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->fixtures->access->workspace->getId(), null, 'developer'));
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $teams = self::getContainer()->get(IssueTeamSubscriptionRepository::class);

        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), \sprintf('A redirection for @[Hosting](team:%d), not for @[Support](team:%d).', $hosting->getId(), $support->getId())));
        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), \sprintf('Again, @[Hosting](team:%d)?', $hosting->getId())));

        self::assertSame([$hosting], $teams->findTeams($issue), 'the team follows, once; a team without a role on the project is not mentioned');
        self::assertNotContains('marc@agency.test', $this->subscribers($issue), 'nobody is subscribed for the team');
        self::assertContains($marc, $teams->findMembers($issue), 'its members are written to');
        self::assertNull($issue->getAssigneeTeam(), 'mentioning is not assigning');
    }

    public function testAnExternalTeamFollowsNothingPrivateAsAnExternalUser(): void
    {
        $accounting = $this->commands->dispatch(new CreateTeam('Accounting', false));
        $hosting = $this->commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(Team::class, $accounting);
        self::assertInstanceOf(Team::class, $hosting);
        $this->commands->dispatch(new GrantTeamAccess((int) $accounting->getId(), $this->fixtures->access->workspace->getId(), null, 'client'));
        $this->commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->fixtures->access->workspace->getId(), null, 'developer'));
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $nadia = (int) $this->fixtures->developer->getId();
        $teams = self::getContainer()->get(IssueTeamSubscriptionRepository::class);
        $mention = \sprintf('For @[Accounting](team:%d) and @[Hosting](team:%d).', $accounting->getId(), $hosting->getId());

        $this->commands->dispatch(new AddComment($nadia, $issue->getKey(), $mention, private: true));
        self::assertSame([$hosting], $teams->findTeams($issue), 'a private comment is for internal users: the external team is not subscribed');

        $this->commands->dispatch(new AddComment($nadia, $issue->getKey(), $mention));
        self::assertSame([$accounting, $hosting], $teams->findTeams($issue), 'a public one subscribes both');

        $hidden = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->manager->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->bugs->getId(),
            title: 'Between us',
            description: $mention,
            private: true,
            fields: ['environment' => 'prod'],
        ));
        self::assertInstanceOf(Issue::class, $hidden);
        self::assertSame([$hosting], $teams->findTeams($hidden), 'nor to a private issue');
    }

    public function testAMentionInAPrivateCommentDoesNotSubscribeAnExternalUser(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->manager);
        $claire = $this->fixtures->client;

        $this->commands->dispatch(new AddComment((int) $this->fixtures->developer->getId(), $issue->getKey(), \sprintf('Careful with @[Claire](user:%d).', $claire->getId()), private: true));

        self::assertSame(['nadia@agency.test', 'thomas@agency.test'], $this->subscribers($issue));
    }

    public function testNobodyIsSubscribedToAnIssueTheyCannotSee(): void
    {
        $claire = $this->fixtures->client;
        $issue = $this->commands->dispatch(new CreateIssue(
            actorId: (int) $this->fixtures->manager->getId(),
            projectId: (int) $this->fixtures->access->shop->getId(),
            trackerId: (int) $this->fixtures->tasks->getId(),
            title: 'Internal task',
            description: \sprintf('For @[Claire](user:%d), without telling her.', $claire->getId()),
            fields: ['environment' => 'prod'],
        ));

        self::assertSame(['thomas@agency.test'], $this->subscribers($issue), 'the tracker is private: the client, mentioned, is not subscribed');
    }

    public function testTakingPartAgainSubscribesAgain(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $claire = (int) $this->fixtures->client->getId();

        $this->commands->dispatch(new UnfollowIssue($claire, $issue->getKey()));
        self::assertSame(['thomas@agency.test'], $this->subscribers($issue));

        $this->commands->dispatch(new AddComment($claire, $issue->getKey(), 'Any news?'));
        self::assertSame(['claire@client.test', 'thomas@agency.test'], $this->subscribers($issue));
    }

    public function testACommentMadeWithAnActionSubscribesItsAuthor(): void
    {
        $issue = $this->fixtures->createIssue($this->fixtures->client);

        $this->commands->dispatch(new ApplyTransition((int) $this->fixtures->developer->getId(), $issue->getKey(), 'start', comment: 'I am on it.'));

        self::assertSame(['claire@client.test', 'nadia@agency.test', 'thomas@agency.test'], $this->subscribers($issue));
    }

    /**
     * @return list<string>
     */
    private function subscribers(Issue $issue): array
    {
        $emails = array_map(static fn (User $user): string => $user->getEmail(), $this->subscriptions->findSubscribers($issue));
        sort($emails);

        return $emails;
    }
}
