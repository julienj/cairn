<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Team;
use App\Message\Command\Issue\UpdateIssue;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * MD-SPT-4 of the scene was written by Claire, is assigned to Nadia, who
 * commented, as did Thomas, who manages the workspace: the three follow
 * it; Léa and Paul do not.
 */
final class SubscribersTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    public function testTheSubscribersAreListed(): void
    {
        $scene = ShopScene::load(self::getContainer());

        $component = $this->createLiveComponent('Issue:Subscribers', ['issue' => $scene->issue('MD-SPT-4')])
            ->actingAs($scene->user('paul.roussel@maison-dupont.test'));
        $render = (string) $component->render();
        self::assertStringContainsString('title="Claire Martin"', $render, 'folded at first: the avatars on the header');

        foreach (['Claire Martin', 'Nadia Benali', 'Thomas Girard'] as $name) {
            self::assertStringContainsString($name, $render);
        }
        self::assertStringNotContainsString('Léa Fontaine', $render);
        self::assertStringNotContainsString('Paul Roussel', $render);
    }

    public function testATeamThatFollowsTheIssueComesFirstAndAMemberUnsubscribesIt(): void
    {
        $scene = ShopScene::load(self::getContainer());
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(Team::class, $team);
        $commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $scene->user('marc.petit@cairn.test')->getId()));
        $commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $scene->user('lea.fontaine@cairn.test')->getId()));
        $commands->dispatch(new GrantTeamAccess((int) $team->getId(), $scene->dupont->getId(), null, 'developer'));
        $thomas = $scene->user('thomas.girard@cairn.test');
        $component = $this->createLiveComponent('Issue:Subscribers', ['issue' => $scene->issue('MD-SPT-4')])->actingAs($thomas);
        self::assertStringNotContainsString('Hosting', (string) $component->render());

        $commands->dispatch(new UpdateIssue((int) $thomas->getId(), 'MD-SPT-4', assigneeTeamId: $team->getId()));
        $render = (string) $component->call('refresh')->render();

        self::assertStringContainsString('Hosting', $render);
        self::assertStringContainsString('2 members', $render, 'those a change is written to');
        self::assertStringNotContainsString('Marc Petit', $render, 'the members are not listed: they do not follow it');
        self::assertStringContainsString('Claire Martin', $render, 'the followers stay');
        self::assertStringNotContainsString('data-live-action-param="unfollowTeam"', $render, 'Thomas is not part of the team');

        $commands->dispatch(new UpdateIssue((int) $thomas->getId(), 'MD-SPT-4', assigneeId: $thomas->getId()));
        self::assertStringContainsString('Hosting', (string) $component->call('refresh')->render(), 'the team keeps following what it was given');

        $asMarc = $this->createLiveComponent('Issue:Subscribers', ['issue' => $scene->issue('MD-SPT-4')])->actingAs($scene->user('marc.petit@cairn.test'));
        self::assertStringContainsString('data-live-action-param="unfollowTeam"', (string) $asMarc->render());
        $rendered = $asMarc->call('unfollowTeam', ['team' => $team->getId()])->render();
        self::assertNotNull($asMarc->getEmittedEvent($rendered, 'issue:changed'));
        self::assertStringNotContainsString('Hosting', (string) $rendered);
    }
}
