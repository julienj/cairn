<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Team;

use App\Entity\Team;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Repository\TeamAccessRepository;
use App\Security\AccessControl;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * In the scene: Julie administers, Thomas manages Maison Dupont (the
 * shop, the intranet), Marc develops on the intranet only, Claire is the
 * client, Sophie is Travelia's.
 */
final class AccessesTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Team $hosting;
    private Team $accounting;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->hosting = $this->team('Hosting', true, 'marc.petit@cairn.test');
        $this->accounting = $this->team('Accounting', false, 'sophie.bernard@travelia.test');
    }

    public function testATeamIsGivenARoleOnAWorkspaceThenItIsChangedAndRevoked(): void
    {
        $marc = $this->scene->user('marc.petit@cairn.test');
        $component = $this->createLiveComponent('Team:Accesses', ['workspace' => $this->scene->dupont])->actingAs($this->scene->user('julie.admin@cairn.test'));
        self::assertStringContainsString('No team has access to this workspace yet.', (string) $component->render());

        $render = (string) $component->call('chooseTeam', ['team' => $this->hosting->getId()])->render();
        self::assertStringContainsString('Hosting</span>', $render, 'the picker shows the choice');
        $component->set('newRole', 'developer')->call('grant');

        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame('developer', $this->roleOf($marc, 'shop'));
        $render = (string) $component->render();
        self::assertStringContainsString('Marc Petit', $render, 'a row names the members of the team');

        $access = self::getContainer()->get(TeamAccessRepository::class)->findOne($this->hosting, $this->scene->dupont);
        self::assertNotNull($access);
        $component->set('roles.'.$access->getId(), 'project_manager')->call('changeRole', ['access' => $access->getId()]);
        self::assertSame('project_manager', $this->roleOf($marc, 'shop'));

        $component->call('confirmRevoke', ['access' => $access->getId()]);
        self::assertStringContainsString('Revoke this access?', (string) $component->render());
        $component->call('revoke');
        self::assertNull($this->roleOf($marc, 'shop'));
    }

    public function testAnExternalTeamIsOfferedNoInternalRoleAndARefusalIsShownInPlace(): void
    {
        $component = $this->createLiveComponent('Team:Accesses', ['workspace' => $this->scene->dupont])->actingAs($this->scene->user('julie.admin@cairn.test'));

        $render = (string) $component->call('chooseTeam', ['team' => $this->accounting->getId()])->render();
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringContainsString('<option value="client"', $offered);
        self::assertStringNotContainsString('<option value="developer"', $offered);

        $render = (string) $component->set('newRole', 'developer')->call('grant')->render();
        self::assertStringContainsString('is for internal teams only', $render, 'whatever the request says');
        self::assertSame([], self::getContainer()->get(TeamAccessRepository::class)->findOfTeam($this->accounting));
    }

    public function testTheAccessesOfATeamAreListedAndOneIsAddedOnAProject(): void
    {
        $this->grant($this->hosting, workspaceId: $this->scene->dupont->getId(), role: 'developer');
        $component = $this->createLiveComponent('Team:Accesses', ['team' => $this->hosting])->actingAs($this->scene->user('julie.admin@cairn.test'));
        self::assertStringContainsString('The whole Maison Dupont workspace', (string) $component->render());

        $render = (string) $component->call('chooseTarget', ['target' => 'project-'.$this->scene->shop->getId()])->render();
        self::assertStringContainsString('MD-SPT · Online shop</span>', $render, 'the picker shows the choice');
        $component->set('newRole', 'project_manager')->call('grant');

        self::assertCount(2, self::getContainer()->get(TeamAccessRepository::class)->findOfTeam($this->hosting));
        self::assertStringContainsString('Choose the team or where to grant access, and the role.', (string) $component->call('grant')->render());
    }

    public function testTheManagerOfAProjectSeesTheTeamsOfTheWorkspaceAndGrantsWhatWidensThem(): void
    {
        $this->grant($this->hosting, workspaceId: $this->scene->dupont->getId(), role: 'developer');
        $component = $this->createLiveComponent('Team:Accesses', ['project' => $this->scene->shop])->actingAs($this->scene->user('thomas.girard@cairn.test'));

        $render = (string) $component->render();
        self::assertStringContainsString('Teams of the workspace Maison Dupont', $render);
        self::assertStringContainsString('No team holds a role on this project directly.', $render);
        self::assertStringNotContainsString('/admin/teams/', $render, 'no way into the administration for a manager');

        $render = (string) $component->call('chooseTeam', ['team' => $this->hosting->getId()])->render();
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringContainsString('<option value="project_manager"', $offered);
        self::assertStringNotContainsString('<option value="developer"', $offered, 'what the team holds on the workspace widens nothing');

        $component->set('newRole', 'project_manager')->call('grant');
        self::assertSame('project_manager', $this->roleOf($this->scene->user('marc.petit@cairn.test'), 'shop'));
        self::assertStringContainsString('inherits &quot;Developer&quot;', (string) $component->render());
    }

    public function testOnlyWhoManagesTheMembersDrawsIt(): void
    {
        try {
            $this->createLiveComponent('Team:Accesses', ['project' => $this->scene->shop])->actingAs($this->scene->user('nadia.benali@cairn.test'))->render();
            self::fail('A developer drew the accesses of the teams of a project.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Team:Accesses', ['team' => $this->hosting])->actingAs($this->scene->user('thomas.girard@cairn.test'))->render();
    }

    private function team(string $name, bool $internal, string ...$members): Team
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam($name, $internal));
        self::assertInstanceOf(Team::class, $team);
        foreach ($members as $email) {
            $commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $this->scene->user($email)->getId()));
        }

        return $team;
    }

    private function grant(Team $team, string $role, ?int $workspaceId = null, ?int $projectId = null): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new GrantTeamAccess((int) $team->getId(), $workspaceId, $projectId, $role));
    }

    private function roleOf(\App\Entity\User $user, string $project): ?string
    {
        return self::getContainer()->get(AccessControl::class)->roleOn($user, $this->scene->{$project});
    }
}
