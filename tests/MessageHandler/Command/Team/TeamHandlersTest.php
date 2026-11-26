<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Team;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Entity\User;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\DeleteTeam;
use App\Message\Command\Team\RemoveTeamMember;
use App\Message\Command\Team\UpdateTeam;
use App\Message\Command\User\DeleteUser;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\MessageHandler\Command\Team\TeamException;
use App\MessageHandler\Command\User\UserException;
use App\Repository\TeamMemberRepository;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

/**
 * The teams and who is part of them: an internal team takes internal
 * users only, and keeps them internal.
 */
final class TeamHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->fixtures = new AccessFixtures($this->entityManager);
    }

    public function testATeamIsCreatedInternalOrExternalUnderAFreeName(): void
    {
        $hosting = $this->team('  Hosting ', internal: true);
        $accounting = $this->team('Accounting', internal: false);

        self::assertNotNull($hosting->getId());
        self::assertSame('Hosting', $hosting->getName());
        self::assertTrue($hosting->isInternal());
        self::assertFalse($accounting->isInternal());
    }

    public function testTwoTeamsNeverShareAName(): void
    {
        $this->team('Hosting');

        $this->expectException(TeamException::class);
        $this->commands->dispatch(new CreateTeam('hosting', false));
    }

    public function testATeamHasAName(): void
    {
        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new CreateTeam('  ', true));
    }

    public function testATeamIsRenamedUnlessTheNameIsTaken(): void
    {
        $hosting = $this->team('Hosting');
        $this->team('Support');

        $this->commands->dispatch(new UpdateTeam((int) $hosting->getId(), 'hosting'));
        self::assertSame('hosting', $hosting->getName(), 'its own name in another case is free');
        $this->commands->dispatch(new UpdateTeam((int) $hosting->getId()));
        self::assertSame('hosting', $hosting->getName(), 'what is not given does not change');

        $this->expectException(TeamException::class);
        $this->commands->dispatch(new UpdateTeam((int) $hosting->getId(), 'Support'));
    }

    public function testAnInternalTeamTakesInternalUsersOnly(): void
    {
        $hosting = $this->team('Hosting');
        $yann = $this->fixtures->user('yann@cairn.test', internal: true);
        $claire = $this->fixtures->user('claire@client.test');

        $this->add($hosting, $yann);
        $this->add($hosting, $yann);
        self::assertSame([$yann], $this->membersOf($hosting), 'someone joins a team once');

        try {
            $this->add($hosting, $claire);
            self::fail('An external user joined an internal team.');
        } catch (TeamException $e) {
            self::assertStringContainsString('is external', $e->getMessage());
        }
        self::assertSame([$yann], $this->membersOf($hosting));
    }

    public function testAnExternalTeamTakesAnyone(): void
    {
        $accounting = $this->team('Accounting', internal: false);
        $yann = $this->fixtures->user('yann@cairn.test', internal: true);
        $claire = $this->fixtures->user('claire@client.test');

        $this->add($accounting, $claire);
        $this->add($accounting, $yann);

        self::assertCount(2, $this->membersOf($accounting));
    }

    public function testADeactivatedAccountJoinsNoTeam(): void
    {
        $hosting = $this->team('Hosting');
        $yann = $this->fixtures->user('yann@cairn.test', internal: true);
        $this->commands->dispatch(new UpdateUser((int) $yann->getId(), active: false));

        $this->expectException(TeamException::class);
        $this->add($hosting, $yann);
    }

    public function testAMemberLeavesTheTeam(): void
    {
        $hosting = $this->team('Hosting');
        $yann = $this->fixtures->user('yann@cairn.test', internal: true);
        $marc = $this->fixtures->user('marc@cairn.test', internal: true);
        $this->add($hosting, $yann);
        $this->add($hosting, $marc);

        $this->commands->dispatch(new RemoveTeamMember((int) $hosting->getId(), (int) $yann->getId()));
        $this->commands->dispatch(new RemoveTeamMember((int) $hosting->getId(), (int) $yann->getId()));

        self::assertSame([$marc], $this->membersOf($hosting));
    }

    public function testAMemberOfAnInternalTeamStaysInternal(): void
    {
        $hosting = $this->team('Hosting');
        $accounting = $this->team('Accounting', internal: false);
        $yann = $this->fixtures->user('yann@cairn.test', internal: true);
        $this->add($hosting, $yann);
        $this->add($accounting, $yann);

        try {
            $this->commands->dispatch(new UpdateUser((int) $yann->getId(), internal: false));
            self::fail('A member of an internal team became external.');
        } catch (UserException $e) {
            self::assertStringContainsString('Hosting', $e->getMessage());
            self::assertStringNotContainsString('Accounting', $e->getMessage(), 'an external team holds nobody back');
        }
        self::assertTrue($yann->isInternal());

        $this->commands->dispatch(new RemoveTeamMember((int) $hosting->getId(), (int) $yann->getId()));
        $this->commands->dispatch(new UpdateUser((int) $yann->getId(), internal: false));
        self::assertFalse($yann->isInternal());
        self::assertSame([$yann], $this->membersOf($accounting));
    }

    public function testADeletedTeamLetsItsMembersGo(): void
    {
        $hosting = $this->team('Hosting');
        $yann = $this->fixtures->user('yann@cairn.test', internal: true);
        $this->add($hosting, $yann);
        $teamId = (int) $hosting->getId();

        $this->commands->dispatch(new DeleteTeam($teamId));
        $this->entityManager->clear();

        self::assertNull($this->entityManager->getRepository(Team::class)->find($teamId));
        self::assertSame(0, $this->entityManager->getRepository(TeamMember::class)->count([]));
        self::assertNotNull($this->entityManager->getRepository(User::class)->find($yann->getId()), 'the account is not touched');
    }

    public function testADeletedAccountLeavesItsTeams(): void
    {
        $hosting = $this->team('Hosting');
        $yann = $this->fixtures->user('yann@cairn.test', internal: true);
        $this->add($hosting, $yann);

        $this->commands->dispatch(new DeleteUser((int) $yann->getId()));
        $this->entityManager->clear();

        self::assertSame(0, $this->entityManager->getRepository(TeamMember::class)->count([]));
    }

    private function team(string $name, bool $internal = true): Team
    {
        $team = $this->commands->dispatch(new CreateTeam($name, $internal));
        self::assertInstanceOf(Team::class, $team);

        return $team;
    }

    private function add(Team $team, User $user): void
    {
        $this->commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $user->getId()));
    }

    /**
     * @return list<User>
     */
    private function membersOf(Team $team): array
    {
        return array_map(static fn (TeamMember $member): User => $member->getUser(), self::getContainer()->get(TeamMemberRepository::class)->findOfTeam($team));
    }
}
