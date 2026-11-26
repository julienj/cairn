<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\Team;

use App\Entity\Project;
use App\Entity\Team;
use App\Entity\TeamAccess;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Config\UpdateRole;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\DeleteTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\Command\Team\RemoveTeamMember;
use App\Message\Command\Team\RevokeTeamAccess;
use App\Message\CommandBus;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListVisibleProjects;
use App\Message\Query\Project\ProjectSummary;
use App\MessageHandler\Command\Config\ConfigChangeException;
use App\MessageHandler\Command\Team\TeamException;
use App\Repository\IssueSubscriptionRepository;
use App\Repository\MembershipRepository;
use App\Repository\TeamAccessRepository;
use App\Security\AccessControl;
use App\Security\Referents;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The roles granted to teams, and what their members hold through them.
 * In the fixtures Thomas manages Maison Dupont (the shop, the intranet),
 * Nadia develops there and Claire is its client; the portal belongs to
 * another workspace.
 */
final class TeamAccessHandlersTest extends KernelTestCase
{
    use KernelHelpers;

    private CommandBus $commands;
    private EntityManagerInterface $entityManager;
    private IssueFixtures $issues;
    private AccessFixtures $fixtures;
    private TeamAccessRepository $accesses;
    private AccessControl $access;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->issues = new IssueFixtures($this->entityManager, $this->commands);
        $this->fixtures = $this->issues->access;
        $this->accesses = self::getContainer()->get(TeamAccessRepository::class);
        $this->access = self::getContainer()->get(AccessControl::class);
    }

    public function testEveryMemberHoldsTheRoleOfTheTeamWhereItApplies(): void
    {
        $marc = $this->fixtures->user('marc@agency.test', internal: true);
        $lea = $this->fixtures->user('lea@agency.test', internal: true);
        $hosting = $this->team('Hosting', true, $marc);

        $this->grant($hosting, $this->fixtures->workspace, 'developer');

        self::assertSame('developer', $this->access->roleOn($marc, $this->fixtures->workspace));
        self::assertSame('developer', $this->access->roleOn($marc, $this->fixtures->shop));
        self::assertSame('developer', $this->access->roleOn($marc, $this->fixtures->intranet));
        self::assertNull($this->access->roleOn($marc, $this->fixtures->otherProject), 'another workspace is not theirs');
        self::assertNull($this->access->roleOn($lea, $this->fixtures->shop), 'who is not part of the team holds nothing');

        $this->commands->dispatch(new AddTeamMember((int) $hosting->getId(), (int) $lea->getId()));
        self::assertSame('developer', $this->access->roleOn($lea, $this->fixtures->shop), 'whoever joins holds it at once');
        $this->commands->dispatch(new RemoveTeamMember((int) $hosting->getId(), (int) $lea->getId()));
        self::assertNull($this->access->roleOn($lea, $this->fixtures->shop), 'and loses it when leaving');
    }

    public function testTheBroadestRoleWinsWhoeverItWasGrantedTo(): void
    {
        $hosting = $this->team('Hosting', true, $this->issues->manager, $this->issues->developer);

        $this->grant($hosting, $this->fixtures->shop, 'developer');
        self::assertSame('project_manager', $this->access->roleOn($this->issues->manager, $this->fixtures->shop), 'a team never narrows what someone holds');

        $this->grant($hosting, $this->fixtures->shop, 'project_manager');
        self::assertSame('project_manager', $this->access->roleOn($this->issues->developer, $this->fixtures->shop), 'and widens it');
        self::assertSame('developer', $this->access->roleOn($this->issues->developer, $this->fixtures->intranet), 'where it applies only');
        self::assertCount(1, $this->accesses->findOfTeam($hosting), 'a role granted again replaces the first');
    }

    public function testAnInternalOnlyRoleNeverGoesToAnExternalTeam(): void
    {
        $accounting = $this->team('Accounting', false);

        try {
            $this->grant($accounting, $this->fixtures->workspace, 'developer');
            self::fail('An external team was given an internal-only role.');
        } catch (TeamException $e) {
            self::assertStringContainsString('which is external', $e->getMessage());
        }
        self::assertSame([], $this->accesses->findOfTeam($accounting));

        $this->grant($accounting, $this->fixtures->workspace, 'client');
        $this->expectException(ConfigChangeException::class);
        $this->commands->dispatch(new UpdateRole('client', internalOnly: true));
    }

    public function testTheRoleMustExistAndGoOnAWorkspaceOrAProject(): void
    {
        $hosting = $this->team('Hosting', true);

        try {
            $this->grant($hosting, $this->fixtures->workspace, 'astronaut');
            self::fail('An unknown role was granted.');
        } catch (TeamException) {
        }

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->fixtures->workspace->getId(), $this->fixtures->shop->getId(), 'developer'));
    }

    public function testARoleOnAProjectMustWidenTheOneTheTeamHoldsOnItsWorkspaceWhateverItsMembersHold(): void
    {
        $hosting = $this->team('Hosting', true, $this->issues->manager);
        $this->grant($hosting, $this->fixtures->shop, 'developer');
        self::assertSame('developer', $this->accesses->findOne($hosting, $this->fixtures->shop)?->getRole(), 'narrower than what its member holds, and granted all the same');

        $this->grant($hosting, $this->fixtures->workspace, 'developer');
        try {
            $this->grant($hosting, $this->fixtures->intranet, 'developer');
            self::fail('A role that widens nothing was granted.');
        } catch (TeamException $e) {
            self::assertStringContainsString('does not widen', $e->getMessage());
        }
        $this->grant($hosting, $this->fixtures->intranet, 'project_manager');
        self::assertSame('project_manager', $this->accesses->findOne($hosting, $this->fixtures->intranet)?->getRole());
    }

    public function testAnExternalMemberSeesTheProjectsOfTheirTeamAndIsListedThere(): void
    {
        $lucie = $this->fixtures->user('lucie@client.test');
        $accounting = $this->team('Accounting', false, $lucie);
        self::assertFalse($this->access->canView($lucie, $this->fixtures->shop));
        self::assertSame([], $this->visibleProjects($lucie));

        $this->grant($accounting, $this->fixtures->shop, 'client');

        self::assertTrue($this->access->canView($lucie, $this->fixtures->shop));
        self::assertFalse($this->access->canView($lucie, $this->fixtures->intranet));
        self::assertSame(['MD-SPT'], $this->visibleProjects($lucie), 'the SQL agrees');
        self::assertContains($lucie, self::queryBus()->ask(new ListProjectMembers($this->fixtures->shop)));
        self::assertNotContains($lucie, self::queryBus()->ask(new ListProjectMembers($this->fixtures->intranet)));
    }

    public function testAMemberOfATeamThatFollowsNewIssuesIsSubscribedToThem(): void
    {
        $marc = $this->fixtures->user('marc@agency.test', internal: true);
        $lea = $this->fixtures->user('lea@agency.test', internal: true);
        $this->grant($this->team('Leads', true, $marc), $this->fixtures->workspace, 'project_manager');
        $this->grant($this->team('Hosting', true, $lea), $this->fixtures->workspace, 'developer');

        $issue = $this->issues->createIssue($this->issues->client);

        $subscriptions = self::getContainer()->get(IssueSubscriptionRepository::class);
        self::assertNotNull($subscriptions->findOne($issue, $marc), 'project managers follow new issues, through a team too');
        self::assertNull($subscriptions->findOne($issue, $lea));
    }

    public function testAReferentGivenABroaderRoleByATeamNoLongerAnswersForTheirs(): void
    {
        $referents = self::getContainer()->get(Referents::class);
        self::getContainer()->get(MembershipRepository::class)->findByUser($this->issues->developer)[0]->designateReferent();
        $this->entityManager->flush();
        self::assertSame($this->issues->developer, $referents->of($this->fixtures->shop, 'developer'));

        $this->grant($this->team('Leads', true, $this->issues->developer), $this->fixtures->shop, 'project_manager');

        self::assertNull($referents->of($this->fixtures->shop, 'developer'), 'the existing rule: another role on the project, no longer its referent');
        self::assertSame($this->issues->developer, $referents->of($this->fixtures->intranet, 'developer'), 'still the referent where the team gives nothing');
    }

    public function testRevokingOrDeletingTheTeamTakesTheRoleBack(): void
    {
        $marc = $this->fixtures->user('marc@agency.test', internal: true);
        $hosting = $this->team('Hosting', true, $marc);
        $this->grant($hosting, $this->fixtures->workspace, 'developer');
        $this->grant($hosting, $this->fixtures->otherProject, 'developer');

        $this->commands->dispatch(new RevokeTeamAccess((int) $this->accesses->findOne($hosting, $this->fixtures->workspace)?->getId()));
        self::assertNull($this->access->roleOn($marc, $this->fixtures->shop), 'the access control forgets it at once');
        self::assertSame('developer', $this->access->roleOn($marc, $this->fixtures->otherProject));

        $this->commands->dispatch(new DeleteTeam((int) $hosting->getId()));
        self::assertNull($this->access->roleOn($marc, $this->fixtures->otherProject));
        self::assertSame(0, $this->entityManager->getRepository(TeamAccess::class)->count([]));
    }

    public function testAProjectManagerGrantsAndRevokesOnTheirProjectOnly(): void
    {
        $thomas = $this->fixtures->user('theo@agency.test', internal: true);
        $this->fixtures->grantOnProject($thomas, $this->fixtures->shop, 'project_manager');
        $marc = $this->fixtures->user('marc@agency.test', internal: true);
        $hosting = $this->team('Hosting', true, $marc);
        $teamId = (int) $hosting->getId();
        $actorId = (int) $thomas->getId();

        $this->commands->dispatch(new GrantTeamAccess($teamId, null, $this->fixtures->shop->getId(), 'developer', $actorId));
        self::assertSame('developer', $this->access->roleOn($marc, $this->fixtures->shop));

        foreach ([
            'a workspace stays the administration\'s' => new GrantTeamAccess($teamId, $this->fixtures->workspace->getId(), null, 'developer', $actorId),
            'another project is not theirs' => new GrantTeamAccess($teamId, null, $this->fixtures->intranet->getId(), 'developer', $actorId),
            'who does not manage the members grants nothing' => new GrantTeamAccess($teamId, null, $this->fixtures->shop->getId(), 'project_manager', (int) $this->issues->developer->getId()),
        ] as $reason => $command) {
            try {
                $this->commands->dispatch($command);
                self::fail($reason);
            } catch (AccessDeniedException) {
            }
        }

        $this->commands->dispatch(new RevokeTeamAccess((int) $this->accesses->findOne($hosting, $this->fixtures->shop)?->getId(), $actorId));
        self::assertNull($this->access->roleOn($marc, $this->fixtures->shop));
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

    private function grant(Team $team, Project|Workspace $scope, string $role): void
    {
        $this->commands->dispatch(new GrantTeamAccess(
            (int) $team->getId(),
            $scope instanceof Workspace ? $scope->getId() : null,
            $scope instanceof Project ? $scope->getId() : null,
            $role,
        ));
    }

    /**
     * @return list<string>
     */
    private function visibleProjects(User $viewer): array
    {
        return array_map(static fn (ProjectSummary $summary): string => $summary->project->getIssuePrefix(), self::queryBus()->ask(new ListVisibleProjects($viewer)));
    }
}
