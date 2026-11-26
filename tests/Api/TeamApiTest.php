<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Team;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;

/**
 * The teams through the API: the hosting team (Marc) holds a role on
 * Maison Dupont, the support team holds none.
 */
final class TeamApiTest extends ApiTestCase
{
    private Team $hosting;
    private Team $support;

    protected function setUp(): void
    {
        parent::setUp();
        $commands = self::getContainer()->get(CommandBus::class);
        $hosting = $commands->dispatch(new CreateTeam('Hosting', true));
        $support = $commands->dispatch(new CreateTeam('Support', true));
        \assert($hosting instanceof Team && $support instanceof Team);
        $commands->dispatch(new AddTeamMember((int) $hosting->getId(), (int) $this->scene->user(self::OUTSIDER)->getId()));
        $commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $this->hosting = $hosting;
        $this->support = $support;
    }

    public function testTheTeamsOfAProjectAreWhatAnIssueMayBeAssignedTo(): void
    {
        $teams = $this->api('GET', '/api/projects/MD-SPT/teams', $this->tokenFor(self::DEVELOPER)->secret);

        self::assertResponseIsSuccessful();
        self::assertSame([['id' => $this->hosting->getId(), 'name' => 'Hosting', 'internal' => true]], array_map(static fn (array $team): array => ['id' => $team['id'], 'name' => $team['name'], 'internal' => $team['internal']], $teams['member']));

        $this->api('GET', '/api/projects/MD-XXX/teams', $this->tokenFor(self::DEVELOPER)->secret);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnIssueIsGivenToATeamOrToAPersonAndBackToNobody(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;
        $nadia = $this->scene->user(self::DEVELOPER);

        $given = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assigneeTeam' => $this->hosting->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame(['@type' => 'NamedRef', 'id' => $this->hosting->getId(), 'name' => 'Hosting'], $given['assigneeTeam']);
        self::assertArrayNotHasKey('assignee', $given);

        $kept = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['criticality' => 'low']);
        self::assertSame('Hosting', $kept['assigneeTeam']['name'], 'A property left out keeps its value.');

        $taken = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assignee' => $nadia->getId()]);
        self::assertSame('Nadia Benali', $taken['assignee']['name']);
        self::assertArrayNotHasKey('assigneeTeam', $taken, 'A person or a team, never both.');

        $back = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assignee' => null, 'assigneeTeam' => $this->hosting->getId()]);
        self::assertSame('Hosting', $back['assigneeTeam']['name'], 'named with the person emptied, the team is given the issue');

        $same = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assigneeTeam' => null]);
        self::assertSame('Hosting', $same['assigneeTeam']['name'], 'a team sent as null empties nothing: the assignee is what unassigns');

        $unassigned = $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assignee' => null]);
        self::assertArrayNotHasKey('assigneeTeam', $unassigned, 'nobody is neither a person nor a team');
        self::assertNull($this->issue('MD-SPT-2')->getAssigneeTeam());

        $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assignee' => $nadia->getId(), 'assigneeTeam' => $this->hosting->getId()]);
        self::assertResponseStatusCodeSame(422);
        $this->api('PATCH', '/api/issues/MD-SPT-2', $secret, ['assigneeTeam' => $this->support->getId()]);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->issue('MD-SPT-2')->getAssigneeTeam(), 'a team without a role on the project is refused');
    }

    public function testAnIssueIsCreatedForATeamAndTheListFiltersByTeam(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $created = $this->api('POST', '/api/issues', $secret, [
            'project' => 'MD-SPT',
            'tracker' => $this->scene->bugs->getId(),
            'title' => 'Renew the certificate',
            'fields' => ['environment' => 'prod'],
            'assigneeTeam' => $this->hosting->getId(),
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Hosting', $created['assigneeTeam']['name']);

        $ofTeam = $this->api('GET', '/api/issues?project=MD-SPT&assigneeTeam[]='.$this->hosting->getId(), $secret);
        self::assertSame([$created['key']], array_column($ofTeam['member'], 'key'));

        $unassigned = $this->api('GET', '/api/issues?project=MD-SPT&assignee[]=none', $secret);
        self::assertNotContains($created['key'], array_column($unassigned['member'], 'key'), 'given to a team is not unassigned');

        $this->api('GET', '/api/issues?project=MD-SPT&assigneeTeam[]=hosting', $secret);
        self::assertResponseStatusCodeSame(400);
    }
}
