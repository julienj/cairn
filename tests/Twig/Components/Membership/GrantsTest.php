<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Membership;

use App\Entity\User;
use App\Message\Command\Membership\GrantMembership;
use App\Message\Command\Membership\RevokeMembership;
use App\Message\Command\Membership\SetReferent;
use App\Message\Command\User\CreateUser;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\Repository\MembershipRepository;
use App\Repository\UserRepository;
use App\Tests\Scene\ShopScene;
use App\Twig\Components\Membership\Grants;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * In the scene: Thomas manages Maison Dupont, Claire is its client, Paul
 * reads it; Nadia develops on the shop only, Sophie is Travelia's client.
 */
final class GrantsTest extends KernelTestCase
{
    use InteractsWithLiveComponents;
    use MailerAssertionsTrait;

    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheMembersOfAWorkspaceAreListedAndOneIsAdded(): void
    {
        $component = $this->onWorkspace();
        $render = (string) $component->render();
        self::assertStringContainsString('Thomas Girard', $render);
        self::assertStringContainsString('Paul Roussel', $render);

        $sophie = $this->user('sophie.bernard@travelia.test');
        self::assertStringContainsString('Travelia', $render, 'the picker lists her with her company');
        $render = (string) $component->call('chooseUser', ['user' => $sophie->getId()])->render();
        self::assertStringContainsString('aria-expanded="false" aria-haspopup="listbox"', $render);
        self::assertStringContainsString('Sophie Bernard</span>', $render, 'the picker shows the choice');
        self::assertStringNotContainsString('<option value="developer"', explode('data-model="newRole"', $render)[1], 'no internal role offered to an external user');

        $component->set('newRole', 'client')->call('grant');

        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));
        self::assertSame('client', $this->roleOf($sophie, 'MD'));
    }

    public function testARefusalIsShownInPlace(): void
    {
        $component = $this->onWorkspace();
        $sophie = $this->user('sophie.bernard@travelia.test');

        $component->call('chooseUser', ['user' => $sophie->getId()])->set('newRole', 'developer')->call('grant');

        self::assertStringContainsString('is for internal users only', (string) $component->render());
        self::assertNull($this->roleOf($sophie, 'MD'));
    }

    public function testARoleIsChangedThenRevokedAfterAConfirmation(): void
    {
        $paul = $this->user('paul.roussel@maison-dupont.test');
        $membership = self::getContainer()->get(MembershipRepository::class)->findByUser($paul)[0];
        $component = $this->onWorkspace();

        $component->set('roles.'.$membership->getId(), 'client')->call('changeRole', ['membership' => $membership->getId()]);
        self::assertSame('client', $this->roleOf($paul, 'MD'));

        $component->call('confirmRevoke', ['access' => $membership->getId()]);
        self::assertStringContainsString('Revoke this access?', (string) $component->render());
        $component->call('revoke');

        self::assertNull($this->roleOf($paul, 'MD'));
    }

    public function testAUserGetsARoleOnAProject(): void
    {
        $nadia = $this->user('nadia.benali@cairn.test');
        $component = $this->createLiveComponent('Membership:Grants', ['user' => $nadia])->actingAs($this->user('julie.admin@cairn.test'));
        self::assertStringContainsString('MD-SPT', (string) $component->render());

        $render = (string) $component->call('chooseTarget', ['target' => 'project-'.$this->scene->intranet->getId()])->render();
        self::assertStringContainsString('MD-INT · Intranet</span>', $render, 'the picker shows the choice');
        $component->set('newRole', 'developer')->call('grant');

        self::assertCount(2, self::getContainer()->get(MembershipRepository::class)->findByUser($nadia));
    }

    public function testTheManagerOfAProjectWidensAnInheritedRoleOnIt(): void
    {
        $paul = $this->user('paul.roussel@maison-dupont.test');
        $component = $this->onProject('thomas.girard@cairn.test');
        $render = (string) $component->render();
        self::assertStringContainsString('Paul Roussel', $render, 'inherited from Maison Dupont');
        self::assertStringContainsString('Nadia Benali', $render, 'held on the shop');

        $render = (string) $component->set('adding', true)->set('search', 'Paul.Roussel@maison-dupont.test')->call('choose', ['user' => $paul->getId()])->render();
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringContainsString('<option value="client"', $offered);
        self::assertStringNotContainsString('<option value="client_readonly"', $offered, 'what he inherits widens nothing');

        $component->set('newRole', 'client')->call('grant');

        $membership = self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $paul, 'project' => $this->scene->shop]);
        self::assertSame('client', $membership?->getRole());
        self::assertStringContainsString('inherits &quot;Client (read-only)&quot;', (string) $component->render());
    }

    public function testOnAProjectWhatIsTypedFindsTheAccountsAndSaysWhatEachMayBeGiven(): void
    {
        self::getContainer()->get(CommandBus::class)->dispatch(new UpdateUser((int) $this->user('marc.petit@cairn.test')->getId(), active: false));
        $component = $this->onProject('thomas.girard@cairn.test')->set('adding', true);

        self::assertStringNotContainsString('role="option"', (string) $component->set('search', 'a')->render(), 'nothing until two characters');

        $render = (string) $component->set('search', 'nadia')->render();
        self::assertStringContainsString('Nadia Benali', $render);
        self::assertStringContainsString('nadia.benali@cairn.test', $render, 'the address tells two people of the same name apart');
        self::assertStringContainsString('Already has a role here', $render);
        self::assertStringNotContainsString('data-live-action-param="choose"', $render, 'and she cannot be chosen');
        $render = (string) $component->call('choose', ['user' => $this->user('nadia.benali@cairn.test')->getId()])->render();
        self::assertStringNotContainsString('data-live-action-param="unchoose"', $render, 'whatever the request says');

        $render = (string) $component->set('search', 'Maison Dupont')->render();
        self::assertStringContainsString('Claire Martin', $render, 'found by the company');
        self::assertStringContainsString('Paul Roussel', $render);
        self::assertStringContainsString('Inherits the broadest role', $render);

        $render = (string) $component->set('search', 'petit')->render();
        self::assertStringContainsString('Marc Petit', $render, 'a deactivated account shows, so that nobody creates it again');
        self::assertStringContainsString('Deactivated account', $render);
        self::assertStringNotContainsString('data-live-action-param="choose"', $render);
        $render = (string) $component->set('search', 'marc.petit@cairn.test')->render();
        self::assertStringNotContainsString('data-live-action-param="startInvite"', $render, 'its address invites nobody');
        self::assertStringNotContainsString('data-model="norender|firstName"', (string) $component->call('startInvite')->render());

        self::assertStringContainsString('No account matches', (string) $component->set('search', 'nobody is called that')->render());

        $render = (string) $component->set('search', 'bern')->render();
        self::assertStringContainsString('Sophie Bernard', $render);
        self::assertStringContainsString('Travelia', $render);
        self::assertStringNotContainsString('data-model="newRole"', $render, 'no role before someone is chosen');

        $render = (string) $component->call('choose', ['user' => $this->user('sophie.bernard@travelia.test')->getId()])->render();
        self::assertStringNotContainsString('role="option"', $render, 'the search gives way to who was chosen');
        self::assertStringContainsString('data-live-action-param="unchoose"', $render);
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringNotContainsString('<option value="developer"', $offered, 'external');

        $render = (string) $component->call('unchoose')->render();
        self::assertStringContainsString('value="bern"', $render, 'back to the search as it was');
        self::assertStringContainsString('Sophie Bernard', $render);

        $component->call('choose', ['user' => $this->user('sophie.bernard@travelia.test')->getId()])->set('newRole', 'client')->call('grant');
        self::assertSame('client', self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $this->user('sophie.bernard@travelia.test'), 'project' => $this->scene->shop])?->getRole());

        $render = (string) $component->render();
        self::assertStringNotContainsString('role="dialog"', $render, 'the dialog closes once the access is given');
        self::assertStringContainsString('Sophie Bernard', $render, 'and the list shows her');

        $render = (string) $component->set('adding', true)->render();
        self::assertStringNotContainsString('data-model="newRole"', $render, 'the dialog opens again emptied');
        self::assertStringNotContainsString('role="option"', $render);
    }

    public function testOnAProjectTheSearchShowsAFewAccountsAndSaysHowManyMoreMatch(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        foreach (range(1, 8) as $number) {
            $commands->dispatch(new CreateUser(\sprintf('agent%d@natura.test', $number), 'Agent', \sprintf('Number %d', $number)));
        }
        $component = $this->onProject('thomas.girard@cairn.test')->set('adding', true);

        $render = (string) $component->set('search', 'natura')->render();
        self::assertSame(Grants::SUGGESTIONS, substr_count($render, 'data-live-action-param="choose"'));
        self::assertStringContainsString('2 more accounts match', $render);

        $render = (string) $component->set('search', 'agent8@natura.test')->render();
        self::assertSame(1, substr_count($render, 'data-live-action-param="choose"'));
        self::assertStringNotContainsString('more account', $render);
    }

    public function testOnAProjectAnUnknownAddressGetsAnExternalAccountARoleAndAnInvitation(): void
    {
        $component = $this->onProject('thomas.girard@cairn.test')->set('adding', true);

        $render = (string) $component->set('search', 'remi.colin@natura.test')->render();
        self::assertStringContainsString('Invite remi.colin@natura.test', $render);
        self::assertStringNotContainsString('data-model="norender|firstName"', $render, 'nothing is created before it is asked for');

        $render = (string) $component->call('startInvite')->render();
        self::assertStringContainsString('an external account will be created', $render);
        self::assertStringContainsString('value="Remi"', $render, 'the first name read from the address');
        self::assertStringContainsString('Maison Dupont', explode('data-controller="combobox"', $render)[1], 'the companies known are suggested');
        $offered = explode('data-model="newRole"', $render)[1];
        self::assertStringNotContainsString('<option value="developer"', $offered, 'a new account is external');

        $component->set('firstName', 'Rémi')->set('lastName', 'Colin')->set('company', 'Natura')->set('newRole', 'client')->call('invite');

        $remi = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'remi.colin@natura.test']);
        self::assertNotNull($remi);
        self::assertFalse($remi->isInternal());
        self::assertFalse($remi->hasPassword());
        self::assertSame('Natura', $remi->getCompany());
        self::assertSame('client', self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $remi, 'project' => $this->scene->shop])?->getRole());
        self::assertEmailCount(1);
        self::assertNotNull($component->getDispatchedBrowserEvent($component->render(), 'toast:show'));

        $render = (string) $component->render();
        self::assertStringContainsString('Rémi Colin', $render);
        self::assertStringContainsString('Invited', $render, 'waiting for his password');
        self::assertStringContainsString('data-live-action-param="resend"', $render);

        $component->call('resend', ['user' => $remi->getId()]);
        $rendered = $component->render();
        self::assertStringNotContainsString('role="alert"', (string) $rendered);
        self::assertNotNull($component->getDispatchedBrowserEvent($rendered, 'toast:show'), 'invited again');
    }

    public function testOnAProjectAnInvitationNeedsAnAddressAndAName(): void
    {
        $component = $this->onProject('thomas.girard@cairn.test')->set('adding', true);

        $render = (string) $component->set('search', 'paul@')->render();
        self::assertStringNotContainsString('data-live-action-param="startInvite"', $render, 'what CreateUser would refuse is no new account');
        self::assertStringNotContainsString('data-model="norender|firstName"', (string) $component->call('startInvite')->render());

        $component->set('search', 'remi@natura.test')->call('startInvite')->set('newRole', 'client')->call('invite');
        self::assertStringContainsString('first name and a last name', (string) $component->render());
        self::assertNull(self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'remi@natura.test']));
        self::assertEmailCount(0);

        // A role refused once the account is created leaves the account, found like any other.
        $component->set('search', 'remi.colin@natura.test')->call('startInvite')->set('firstName', 'Rémi')->set('lastName', 'Colin')->set('newRole', 'developer')->call('invite');
        $render = (string) $component->render();
        self::assertStringContainsString('role="alert"', $render, 'an external account takes no internal role');
        $remi = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'remi.colin@natura.test']);
        self::assertNotNull($remi);
        self::assertStringContainsString('data-live-user-param="'.$remi->getId().'"', $render, 'he is chosen to be given another role');
        self::assertEmailCount(0);
    }

    public function testOnAProjectTheDialogAddingSomeoneOpensAndClosesEmptied(): void
    {
        $component = $this->onProject('thomas.girard@cairn.test');
        self::assertStringNotContainsString('role="dialog"', (string) $component->render(), 'closed until asked');

        $lea = self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $this->user('lea.fontaine@cairn.test'), 'project' => $this->scene->shop]);
        self::assertNotNull($lea);
        $component->set('roles.'.$lea->getId(), 'nobody')->call('changeRole', ['membership' => $lea->getId()]);
        self::assertStringContainsString('role="alert"', (string) $component->render(), 'a refusal on a row');
        self::assertStringNotContainsString('role="alert"', (string) $component->call('open')->render(), 'says nothing in the dialog');
        $component->call('close');

        $render = (string) $component->call('open')->set('search', 'jean-marc.du-pont@natura.test')->call('startInvite')->render();
        self::assertStringContainsString('role="dialog"', $render);
        self::assertStringContainsString('value="Jean-Marc"', $render, 'the first name read from the address');
        self::assertStringContainsString('value="Du-Pont"', $render, 'and the last name');

        $render = (string) $component->set('search', 'contact@natura.test')->call('startInvite')->render();
        self::assertStringNotContainsString('value="Jean-Marc"', $render, 'an address without a name guesses none');

        $render = (string) $component->call('close')->render();
        self::assertStringNotContainsString('role="dialog"', $render);
        $render = (string) $component->call('open')->render();
        self::assertStringNotContainsString('natura.test', $render, 'what it held is forgotten');
        self::assertStringNotContainsString('data-model="norender|firstName"', $render);
    }

    public function testTheComponentIsDrawnForWhoManagesTheAccesses(): void
    {
        // Nadia develops on the shop: she neither reads its accesses nor searches the accounts through them.
        try {
            $this->onProject('nadia.benali@cairn.test')->render();
            self::fail('A developer does not draw the accesses of the project.');
        } catch (AccessDeniedException) {
        }
        try {
            $this->createLiveComponent('Membership:Grants', ['workspace' => $this->scene->dupont])->actingAs($this->user('thomas.girard@cairn.test'))->render();
            self::fail('A manager is not the administration.');
        } catch (AccessDeniedException) {
        }

        // A right taken back since the page was drawn stops the component it holds.
        $component = $this->onProject('thomas.girard@cairn.test')->set('adding', true);
        $thomas = self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $this->user('thomas.girard@cairn.test'), 'workspace' => $this->scene->dupont]);
        self::assertNotNull($thomas);
        self::getContainer()->get(CommandBus::class)->dispatch(new RevokeMembership((int) $thomas->getId()));
        $this->expectException(AccessDeniedException::class);
        $component->set('search', 'bern')->render();
    }

    public function testOnAProjectTheManagerDesignatesTheReferentOfARole(): void
    {
        $memberships = self::getContainer()->get(MembershipRepository::class);
        $nadia = $memberships->findOneBy(['user' => $this->user('nadia.benali@cairn.test'), 'project' => $this->scene->shop]);
        $lea = $memberships->findOneBy(['user' => $this->user('lea.fontaine@cairn.test'), 'project' => $this->scene->shop]);
        self::assertNotNull($nadia);
        self::assertNotNull($lea);
        $component = $this->onProject('thomas.girard@cairn.test');
        $developers = static fn (string $render): string => explode('</li>', explode('data-role="developer"', $render)[1])[0];
        self::assertStringContainsString('Nobody', $developers((string) $component->render()), 'nobody is a referent by default');
        self::assertStringNotContainsString('title="Make referent', (string) $component->render(), 'the rows of the lists designate nobody');

        // each call is a request of its own: the memberships are read again after it
        $isReferent = static fn (int $id): bool => (bool) self::getContainer()->get(MembershipRepository::class)->find($id)?->isReferent();
        [$nadiaId, $leaId] = [(int) $nadia->getId(), (int) $lea->getId()];

        $component->call('setReferent', ['membership' => $nadiaId, 'referent' => 1]);
        self::assertTrue($isReferent($nadiaId));
        self::assertStringContainsString('Designated on this project', $developers((string) $component->render()));

        $component->call('setReferent', ['membership' => $leaId, 'referent' => 1]);
        self::assertFalse($isReferent($nadiaId), 'one referent per role');
        self::assertTrue($isReferent($leaId));

        $component->call('setReferent', ['membership' => $leaId, 'referent' => 0]);
        self::assertFalse($isReferent($leaId));
    }

    public function testAProjectShowsWhoAnswersForEachRoleAndWhatItReplaces(): void
    {
        $memberships = self::getContainer()->get(MembershipRepository::class);
        $commands = self::getContainer()->get(CommandBus::class);
        $marc = $this->user('marc.petit@cairn.test');
        $nadia = $memberships->findOneBy(['user' => $this->user('nadia.benali@cairn.test'), 'project' => $this->scene->shop]);
        self::assertNotNull($nadia);
        $commands->dispatch(new GrantMembership((int) $marc->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $inherited = $memberships->findOneBy(['user' => $marc, 'workspace' => $this->scene->dupont]);
        self::assertNotNull($inherited);
        $commands->dispatch(new SetReferent((int) $inherited->getId(), true));

        $component = $this->onProject('thomas.girard@cairn.test');
        $developers = static fn (string $render): string => explode('</li>', explode('data-role="developer"', $render)[1])[0];

        $row = $developers((string) $component->render());
        self::assertStringContainsString('Marc Petit', $row);
        self::assertStringContainsString('Inherited from the workspace', $row);

        $row = $developers((string) $component->call('setReferent', ['membership' => (int) $nadia->getId(), 'referent' => 1])->render());
        self::assertStringContainsString('Designated on this project', $row);
        self::assertStringContainsString('Replaces Marc Petit, the workspace referent', $row);

        $row = $developers((string) $component->call('setReferent', ['membership' => (int) $nadia->getId(), 'referent' => 0])->render());
        self::assertStringContainsString('Inherited from the workspace', $row, 'back to the referent of the workspace');
        self::assertStringNotContainsString('Replaces', $row);
    }

    public function testOnAWorkspaceTheAdministrationDesignatesTheReferentInTheSameCard(): void
    {
        $thomas = self::getContainer()->get(MembershipRepository::class)->findOneBy(['user' => $this->user('thomas.girard@cairn.test'), 'workspace' => $this->scene->dupont]);
        self::assertNotNull($thomas);
        $component = $this->onWorkspace();
        $managers = static fn (string $render): string => explode('</li>', explode('data-role="project_manager"', $render)[1])[0];
        self::assertStringContainsString('No referent', $managers((string) $component->render()));

        $row = $managers((string) $component->call('setReferent', ['membership' => (int) $thomas->getId(), 'referent' => 1])->render());
        self::assertStringContainsString('Answers on the projects of the workspace', $row);
        self::assertTrue((bool) self::getContainer()->get(MembershipRepository::class)->find((int) $thomas->getId())?->isReferent());
    }

    public function testADirectRoleTheWorkspaceOutgrewIsNotOfferedAsReferent(): void
    {
        $nadia = $this->user('nadia.benali@cairn.test');
        $developers = static fn (string $render): string => explode('</li>', explode('data-role="developer"', $render)[1])[0];
        self::assertStringContainsString('Nadia Benali', $developers((string) $this->onProject('thomas.girard@cairn.test')->render()));

        // she develops on the shop, then manages the whole workspace: on the shop she is a manager now
        self::getContainer()->get(CommandBus::class)->dispatch(new GrantMembership((int) $nadia->getId(), $this->scene->dupont->getId(), null, 'project_manager'));

        self::assertStringNotContainsString('Nadia Benali', $developers((string) $this->onProject('thomas.girard@cairn.test')->render()));
    }

    private function onProject(string $viewer): TestLiveComponent
    {
        return $this->createLiveComponent('Membership:Grants', ['project' => $this->scene->shop])->actingAs($this->user($viewer));
    }

    private function onWorkspace(): TestLiveComponent
    {
        return $this->createLiveComponent('Membership:Grants', ['workspace' => $this->scene->dupont])->actingAs($this->user('julie.admin@cairn.test'));
    }

    private function user(string $email): User
    {
        return $this->scene->user($email);
    }

    private function roleOf(User $user, string $workspaceKey): ?string
    {
        foreach (self::getContainer()->get(MembershipRepository::class)->findByUser($user) as $membership) {
            if ($membership->getWorkspace()?->getKey() === $workspaceKey) {
                return $membership->getRole();
            }
        }

        return null;
    }
}
