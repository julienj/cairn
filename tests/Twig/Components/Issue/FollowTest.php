<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\IssueSubscription;
use App\Entity\Team;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * On MD-SPT-4 of the scene: Claire wrote it, so she follows it; Léa
 * develops on the shop but took no part in it.
 */
final class FollowTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheAuthorFollowsAndMayStop(): void
    {
        $component = $this->follow('claire.martin@maison-dupont.test');
        self::assertStringContainsString('Unfollow', (string) $component->render());

        $component->call('unfollow');
        $render = $component->render();

        self::assertStringContainsString('data-live-action-param="follow"', (string) $render);
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertNull($this->subscription('claire.martin@maison-dupont.test'));
    }

    public function testSomeoneElseStartsFollowing(): void
    {
        $component = $this->follow('lea.fontaine@cairn.test');
        self::assertStringContainsString('data-live-action-param="follow"', (string) $component->render());

        $component->call('follow');

        self::assertStringContainsString('Unfollow', (string) $component->render());
        self::assertNotNull($this->subscription('lea.fontaine@cairn.test'));
    }

    public function testWhoHoldsNoRoleOnTheProjectIsOfferedNothing(): void
    {
        // Marc develops on the intranet: he reads this issue of the shop as any internal user.
        $component = $this->follow('marc.petit@cairn.test');

        $render = (string) $component->render();
        self::assertStringNotContainsString('data-live-action-param="follow"', $render);
        self::assertStringNotContainsString('data-live-action-param="unfollow"', $render);

        $component->call('follow');
        self::assertNull($this->subscription('marc.petit@cairn.test'), 'whatever the request says');
        self::assertStringContainsString('role="alert"', (string) $component->render());
    }

    public function testAMemberOfATeamThatFollowsReadsItInsteadOfAButton(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(Team::class, $team);
        $commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $this->scene->user('marc.petit@cairn.test')->getId()));
        $commands->dispatch(new GrantTeamAccess((int) $team->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $commands->dispatch(new UpdateIssue((int) $this->scene->user('thomas.girard@cairn.test')->getId(), 'MD-SPT-4', assigneeTeamId: $team->getId()));

        $render = (string) $this->follow('marc.petit@cairn.test')->render();
        self::assertStringContainsString('Followed by Hosting', $render);
        self::assertStringNotContainsString('data-live-action-param="follow"', $render, 'he is written to already');

        self::assertStringContainsString('data-live-action-param="follow"', (string) $this->follow('lea.fontaine@cairn.test')->render(), 'who is not part of the team is offered to follow');
    }

    private function follow(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Follow', ['issue' => $this->scene->issue('MD-SPT-4')])->actingAs($this->scene->user($email));
    }

    private function subscription(string $email): ?IssueSubscription
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(IssueSubscription::class)
            ->findOneBy(['issue' => $this->scene->issue('MD-SPT-4'), 'user' => $this->scene->user($email)]);
    }
}
