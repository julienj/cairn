<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Template;

use App\Entity\ProjectTemplate;
use App\Entity\Team;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Template\CreateTemplate;
use App\Message\CommandBus;
use App\Repository\TemplateTeamAccessRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * In the scene: Julie administers, Thomas manages a project. The hosting
 * team is internal, the accounting team external.
 */
final class TeamAccessesTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private ProjectTemplate $template;
    private Team $hosting;
    private Team $accounting;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $commands = self::getContainer()->get(CommandBus::class);
        $template = $commands->dispatch(new CreateTemplate('Web project'));
        $hosting = $commands->dispatch(new CreateTeam('Hosting', true));
        $accounting = $commands->dispatch(new CreateTeam('Accounting', false));
        \assert($template instanceof ProjectTemplate && $hosting instanceof Team && $accounting instanceof Team);
        $this->template = $template;
        $this->hosting = $hosting;
        $this->accounting = $accounting;
    }

    public function testTheRolesOfferedFollowTheTeamChosen(): void
    {
        $component = $this->accesses();
        $offered = static fn (string $render): string => explode('data-model="newRole"', $render)[1];

        self::assertStringContainsString('<option value="developer"', $offered((string) $component->render()), 'every role until a team is chosen');

        $render = (string) $component->set('newRole', 'developer')->call('chooseTeam', ['team' => $this->accounting->getId()])->render();
        self::assertStringContainsString('Accounting</span>', $render, 'the picker shows the choice');
        self::assertStringContainsString('<option value="client"', $offered($render));
        self::assertStringNotContainsString('<option value="developer"', $offered($render), 'no internal-only role for an external team');
        self::assertStringContainsString('Choose the team and its role.', (string) $component->call('grant')->render(), 'the role chosen before was forgotten');

        $render = (string) $component->call('chooseTeam', ['team' => $this->hosting->getId()])->render();
        self::assertStringContainsString('<option value="developer"', $offered($render));
    }

    public function testATeamIsGivenARoleThenItIsChangedAndTakenBack(): void
    {
        $component = $this->accesses();

        $component->call('chooseTeam', ['team' => $this->accounting->getId()])->set('newRole', 'developer')->call('grant');
        self::assertStringContainsString('is for internal teams only', (string) $component->render(), 'whatever the request says');
        self::assertSame([], $this->roles());

        $component->call('chooseTeam', ['team' => $this->hosting->getId()])->set('newRole', 'developer')->call('grant');
        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame(['Hosting' => 'developer'], $this->roles());
        self::assertStringNotContainsString('data-live-team-param="'.$this->hosting->getId().'"', (string) $component->render(), 'a team is named once');

        $access = self::getContainer()->get(TemplateTeamAccessRepository::class)->findByTemplate($this->template)[0];
        $component->set('roles.'.$access->getId(), 'project_manager')->call('changeRole', ['access' => $access->getId()]);
        self::assertSame(['Hosting' => 'project_manager'], $this->roles());

        $component->call('confirmRevoke', ['access' => $access->getId()]);
        self::assertStringContainsString('Revoke this access?', (string) $component->render());
        $component->call('revoke');
        self::assertSame([], $this->roles());
    }

    public function testOnlyTheAdministrationDrawsIt(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Template:TeamAccesses', ['template' => $this->template])->actingAs($this->scene->user('thomas.girard@cairn.test'))->render();
    }

    private function accesses(): TestLiveComponent
    {
        return $this->createLiveComponent('Template:TeamAccesses', ['template' => $this->template])->actingAs($this->scene->user('julie.admin@cairn.test'));
    }

    /**
     * @return array<string, string> team name => role
     */
    private function roles(): array
    {
        $roles = [];
        foreach (self::getContainer()->get(TemplateTeamAccessRepository::class)->findByTemplate($this->template) as $access) {
            $roles[$access->getTeam()->getName()] = $access->getRole();
        }

        return $roles;
    }
}
