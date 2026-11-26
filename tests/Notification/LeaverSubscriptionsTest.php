<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Entity\Issue;
use App\Entity\Team;
use App\Entity\User;
use App\Message\Command\Issue\AddComment;
use App\Message\Command\Issue\FollowIssue;
use App\Message\Command\Membership\RevokeMembership;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\DeleteTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\Command\Team\RemoveTeamMember;
use App\Message\Command\Team\RevokeTeamAccess;
use App\Message\CommandBus;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\IssueTeamSubscriptionRepository;
use App\Repository\MembershipRepository;
use App\Repository\TeamAccessRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Whoever leaves a project stops following its issues. Thomas manages
 * Maison Dupont, Nadia develops there, Claire is its client; Léa and Marc
 * are internal users without a role until a test gives them one.
 */
final class LeaverSubscriptionsTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private Issue $issue;
    private User $lea;
    private User $marc;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $this->issue = $this->fixtures->createIssue($this->fixtures->client);
        $this->lea = $this->fixtures->access->user('lea@agency.test', internal: true);
        $this->marc = $this->fixtures->access->user('marc@agency.test', internal: true);
    }

    public function testAnInternalUserWhoLosesTheirRoleOnAProjectNoLongerFollowsItsIssues(): void
    {
        $membership = $this->fixtures->access->grantOnProject($this->lea, $this->fixtures->access->shop, 'developer');
        $this->follow($this->lea);
        self::assertTrue($this->follows($this->lea));

        $this->commands->dispatch(new RevokeMembership((int) $membership->getId()));

        self::assertFalse($this->follows($this->lea), 'she reads the project still, and has no reason to be written to');
        self::assertTrue($this->follows($this->fixtures->manager), 'the others are not touched');
        $this->follow($this->lea);
        self::assertTrue($this->follows($this->lea), 'following again is hers to do');
    }

    public function testARoleKeptAnotherWayKeepsTheSubscriptions(): void
    {
        $direct = $this->fixtures->access->grantOnProject($this->lea, $this->fixtures->access->shop, 'project_manager');
        $hosting = $this->team('Hosting', $this->lea);
        $this->grant($hosting);
        $this->follow($this->lea);

        $this->commands->dispatch(new RevokeMembership((int) $direct->getId()));
        self::assertTrue($this->follows($this->lea), 'her team still gives her a role');

        $this->revoke($hosting);
        self::assertFalse($this->follows($this->lea), 'nothing gives her one any more');
    }

    public function testARoleOnTheWorkspaceTakenBackLeavesEveryProjectOfIt(): void
    {
        $nadia = $this->fixtures->developer;
        $this->follow($nadia);
        $membership = self::getContainer()->get(MembershipRepository::class)->findByUser($nadia)[0];

        $this->commands->dispatch(new RevokeMembership((int) $membership->getId()));

        self::assertFalse($this->follows($nadia));
    }

    public function testATeamThatLosesItsRoleStopsFollowingAndSoDoItsMembers(): void
    {
        $hosting = $this->team('Hosting', $this->marc, $this->fixtures->developer);
        $this->grant($hosting);
        $this->commands->dispatch(new AddComment((int) $this->fixtures->manager->getId(), $this->issue->getKey(), \sprintf('For @[Hosting](team:%d).', $hosting->getId())));
        $this->follow($this->marc);
        $this->follow($this->fixtures->developer);
        $teams = self::getContainer()->get(IssueTeamSubscriptionRepository::class);
        self::assertSame([$hosting], $teams->findTeams($this->issue));

        $this->revoke($hosting);

        self::assertSame([], $teams->findTeams($this->issue), 'a team without a role on the project follows nothing of it');
        self::assertFalse($this->follows($this->marc), 'he held his role through the team only');
        self::assertTrue($this->follows($this->fixtures->developer), 'Nadia keeps the role she holds herself');
    }

    public function testSomeoneWhoLeavesATeamLeavesTheProjectsItGaveThem(): void
    {
        $hosting = $this->team('Hosting', $this->marc, $this->lea);
        $this->grant($hosting);
        $this->commands->dispatch(new AddComment((int) $this->fixtures->manager->getId(), $this->issue->getKey(), \sprintf('For @[Hosting](team:%d).', $hosting->getId())));
        $this->follow($this->marc);
        $this->follow($this->lea);

        $this->commands->dispatch(new RemoveTeamMember((int) $hosting->getId(), (int) $this->marc->getId()));

        self::assertFalse($this->follows($this->marc));
        self::assertTrue($this->follows($this->lea), 'who stays in the team keeps following');
        self::assertSame([$hosting], self::getContainer()->get(IssueTeamSubscriptionRepository::class)->findTeams($this->issue), 'and the team as one');
    }

    public function testADeletedTeamTakesTheSubscriptionsOfThoseItGaveARole(): void
    {
        $hosting = $this->team('Hosting', $this->marc);
        $this->grant($hosting);
        $this->follow($this->marc);

        $this->commands->dispatch(new DeleteTeam((int) $hosting->getId()));

        self::assertFalse($this->follows($this->marc));
        self::assertTrue($this->follows($this->fixtures->client));
    }

    private function team(string $name, User ...$members): Team
    {
        $team = $this->commands->dispatch(new CreateTeam($name, true));
        self::assertInstanceOf(Team::class, $team);
        foreach ($members as $member) {
            $this->commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $member->getId()));
        }

        return $team;
    }

    private function grant(Team $team): void
    {
        $this->commands->dispatch(new GrantTeamAccess((int) $team->getId(), $this->fixtures->access->workspace->getId(), null, 'developer'));
    }

    private function revoke(Team $team): void
    {
        $access = self::getContainer()->get(TeamAccessRepository::class)->findOne($team, $this->fixtures->access->workspace);
        self::assertNotNull($access);
        $this->commands->dispatch(new RevokeTeamAccess((int) $access->getId()));
    }

    private function follow(User $user): void
    {
        $this->commands->dispatch(new FollowIssue((int) $user->getId(), $this->issue->getKey()));
    }

    private function follows(User $user): bool
    {
        return null !== self::getContainer()->get(IssueSubscriptionRepository::class)->findOne($this->issue, $user);
    }
}
