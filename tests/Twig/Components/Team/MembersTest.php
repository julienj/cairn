<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Team;

use App\Entity\Team;
use App\Entity\TeamMember;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\CommandBus;
use App\Repository\TeamMemberRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * In the scene: Julie administers; Thomas, Nadia, Léa and Marc are
 * internal, Claire, Paul and Sophie are clients.
 */
final class MembersTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testSomeoneIsPickedAndAddedThenRemovedAfterAConfirmation(): void
    {
        $team = $this->team('Hosting', true, ['marc.petit@cairn.test']);
        $nadia = $this->scene->user('nadia.benali@cairn.test');
        $component = $this->members($team);

        $render = (string) $component->render();
        self::assertStringContainsString('Marc Petit', $render);
        self::assertStringNotContainsString('data-live-user-param="'.$this->scene->user('marc.petit@cairn.test')->getId().'" data-list-filter', $render);

        $render = (string) $component->call('chooseUser', ['user' => $nadia->getId()])->render();
        self::assertStringContainsString('Nadia Benali</span>', $render, 'the picker shows the choice');
        $component->call('add');
        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame(['Nadia Benali', 'Marc Petit'], $this->namesOf($team));

        $component->call('confirmRemove', ['user' => $nadia->getId()]);
        self::assertStringContainsString('Remove Nadia Benali from the team?', (string) $component->render());
        $component->call('remove');

        self::assertSame(['Marc Petit'], $this->namesOf($team));
    }

    public function testAnInternalTeamOffersInternalUsersOnlyAndRefusesTheOthers(): void
    {
        $team = $this->team('Hosting', true);
        $claire = $this->scene->user('claire.martin@maison-dupont.test');
        $component = $this->members($team);

        self::assertStringNotContainsString('Claire Martin', (string) $component->render());

        $render = (string) $component->call('chooseUser', ['user' => $claire->getId()])->call('add')->render();
        self::assertStringContainsString('is for internal users only', $render, 'whatever the request says');
        self::assertSame([], $this->namesOf($team));
    }

    public function testAnExternalTeamOffersEveryone(): void
    {
        $render = (string) $this->members($this->team('Accounting', false))->render();

        self::assertStringContainsString('Claire Martin', $render);
        self::assertStringContainsString('Nadia Benali', $render);
    }

    public function testNothingIsAddedUntilSomeoneIsChosen(): void
    {
        $team = $this->team('Hosting', true);

        self::assertStringContainsString('Choose the person to add.', (string) $this->members($team)->call('add')->render());
    }

    public function testOnlyTheAdministrationDrawsIt(): void
    {
        $team = $this->team('Hosting', true);

        $this->expectException(AccessDeniedException::class);
        $this->createLiveComponent('Team:Members', ['team' => $team])->actingAs($this->scene->user('thomas.girard@cairn.test'))->render();
    }

    /**
     * @param list<string> $members
     */
    private function team(string $name, bool $internal, array $members = []): Team
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam($name, $internal));
        self::assertInstanceOf(Team::class, $team);
        foreach ($members as $email) {
            $commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $this->scene->user($email)->getId()));
        }

        return $team;
    }

    private function members(Team $team): TestLiveComponent
    {
        return $this->createLiveComponent('Team:Members', ['team' => $team])->actingAs($this->scene->user('julie.admin@cairn.test'));
    }

    /**
     * @return list<string>
     */
    private function namesOf(Team $team): array
    {
        return array_map(static fn (TeamMember $member): string => $member->getUser()->getFullName(), self::getContainer()->get(TeamMemberRepository::class)->findOfTeam($team));
    }
}
