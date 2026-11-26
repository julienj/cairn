<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Membership\RevokeMembership;
use App\Message\Command\Team\AddTeamMember;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Repository\MembershipRepository;
use App\Tests\KernelHelpers;
use App\Tests\Scene\ShopScene;
use App\Tests\Security\TwoFactorHelpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The administration pages. In the scene, Julie administers the instance,
 * Thomas manages Maison Dupont (MD: SPT, INT), Travelia (TR) holds the
 * archived RES project.
 */
final class AdminTest extends WebTestCase
{
    use KernelHelpers;
    use MailerAssertionsTrait;
    use TwoFactorHelpers;

    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testOnlyAdministratorsEnter(): void
    {
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));
        foreach (['/admin/projects', '/admin/workspaces/MD', '/admin/users', '/admin/teams', '/admin/teams/new'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('header a[href="/admin"]', 'no way in for a project manager');
    }

    public function testTheProjectsAreListedByWorkspaceAndFiltered(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('header a[href="/admin"]');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/projects');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Maison Dupont');
        self::assertSelectorTextContains('main', 'Booking site');
        self::assertSelectorTextContains('main', 'Archived');

        $this->client->request('GET', '/admin/projects?state[]=active');
        self::assertSelectorTextNotContains('main', 'Booking site');

        $this->client->request('GET', '/admin/projects?state[]=nonsense');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAProjectIsCreatedInAWorkspace(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/projects/new?workspace=MD');

        $this->client->submitForm('Create', ['project[name]' => 'Application', 'project[key]' => 'spt']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'A project of Maison Dupont already uses the key SPT.');

        $this->client->submitForm('Create', ['project[name]' => 'Application', 'project[key]' => 'app']);
        self::assertResponseRedirects('/admin/workspaces/MD');
        self::assertNotNull($this->entity(Project::class, ['key' => 'APP']));
    }

    public function testKeysMayHoldDigits(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/workspaces/new');
        $this->client->submitForm('Create', ['workspace[name]' => 'Trois Vallées', 'workspace[key]' => '3v']);
        self::assertResponseRedirects('/admin/workspaces/3V');

        $this->client->request('GET', '/admin/projects/new?workspace=3V');
        $this->client->submitForm('Create', ['project[name]' => 'Ski pass 2000', 'project[key]' => 'sp2k']);
        self::assertResponseRedirects('/admin/workspaces/3V');

        $this->client->request('GET', '/admin/workspaces/3V');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/3V/SP2K/issues');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/12/SP2K/issues');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAWorkspaceIsCreatedRenamedAndArchived(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/workspaces/new');
        $this->client->submitForm('Create', ['workspace[name]' => 'Atelier Morel', 'workspace[key]' => 'am']);
        self::assertResponseRedirects('/admin/workspaces/AM');

        $this->client->request('GET', '/admin/workspaces/new');
        $this->client->submitForm('Create', ['workspace[name]' => 'Numbers', 'workspace[key]' => '12']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'at least one letter');

        $this->client->request('GET', '/admin/workspaces/MD/settings');
        $this->client->submitForm('Save', ['workspace[name]' => 'Maison Dupont & fils', 'workspace[archived]' => true]);
        self::assertResponseRedirects('/admin/workspaces/MD/settings');

        $workspace = $this->entity(Workspace::class, ['key' => 'MD']) ?? self::fail('MD vanished');
        self::assertSame('Maison Dupont & fils', $workspace->getName());
        self::assertTrue($workspace->isArchived());

        $this->client->loginUser($this->scene->user('claire.martin@maison-dupont.test'));
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('header a[href="/MD/SPT/issues/new"]', 'read-only');
    }

    public function testAnArchivedProjectIsDeletedOnceItsKeyIsTypedAgain(): void
    {
        $this->scene->budget('Order 2026-01');
        $this->scene->budget('Order 2026-10');
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/projects/MD/SPT/delete');
        self::assertResponseStatusCodeSame(404, 'a project is archived before it is deleted');

        $crawler = $this->client->request('GET', '/admin/workspaces/MD');
        self::assertSelectorNotExists('a[href="/admin/projects/MD/SPT/delete"]');
        $this->client->submit($crawler->filter('form[action="/admin/projects/MD/SPT"]')->form());
        $this->client->followRedirect();
        $this->client->click($this->client->getCrawler()->filter('a[href="/admin/projects/MD/SPT/delete"]')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '10 issues');
        self::assertSelectorTextContains('main', '2 budgets', 'the budgets go with the project, and the page says so');

        $this->client->submitForm('Delete the project', ['deletion[confirmation]' => 'MD-INT']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="deletion"]', 'This is not the key');
        self::assertNotNull($this->entity(Project::class, ['key' => 'SPT']));

        $this->client->submitForm('Delete the project', ['deletion[confirmation]' => 'MD-SPT']);
        self::assertResponseRedirects('/admin/workspaces/MD');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Project "Online shop" deleted');
        self::assertNull($this->entity(Project::class, ['key' => 'SPT']));
        self::assertNotNull($this->entity(Project::class, ['key' => 'INT']));
    }

    public function testAnArchivedWorkspaceIsDeletedFromItsSettings(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/workspaces/MD/settings');
        self::assertSelectorNotExists('a[href="/admin/workspaces/MD/delete"]');
        $this->client->request('GET', '/admin/workspaces/MD/delete');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/admin/workspaces/TR/settings');
        $this->client->submitForm('Save', ['workspace[archived]' => true]);
        $this->client->followRedirect();
        $this->client->clickLink('Delete the workspace…');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Booking site');

        $this->client->submitForm('Delete the workspace', ['deletion[confirmation]' => 'TR']);
        self::assertResponseRedirects('/admin/workspaces');
        self::assertNull($this->entity(Workspace::class, ['key' => 'TR']));
        self::assertNull($this->entity(Project::class, ['key' => 'RES']));
        self::assertNotNull($this->entity(Workspace::class, ['key' => 'MD']));
    }

    public function testAProjectIsArchivedFromTheList(): void
    {
        $this->signInAsAdmin();
        $crawler = $this->client->request('GET', '/admin/workspaces/MD');

        $this->client->submit($crawler->filter('form[action="/admin/projects/MD/INT"]')->form());
        self::assertResponseRedirects('/admin/workspaces/MD');
        self::assertTrue($this->entity(Project::class, ['key' => 'INT'])?->isArchived());
    }

    public function testAnAccountIsCreatedWithoutAPasswordAndInvitedThenEdited(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/users/new');
        self::assertSelectorNotExists('input[type="password"]', 'nobody types a password for someone else');

        $this->client->submitForm('Create and invite', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@natura.test',
            'user[company]' => 'Natura',
            'user[type]' => 'external',
        ]);
        $ines = $this->entity(User::class, ['email' => 'ines@natura.test']);
        self::assertNotNull($ines);
        self::assertFalse($ines->isInternal());
        self::assertFalse($ines->hasPassword());
        self::assertSame('Natura', $ines->getCompany());
        self::assertResponseRedirects('/admin/users/'.$ines->getId());
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', 'ines@natura.test');

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Invited');
        self::assertSelectorExists('form[action="/admin/users/'.$ines->getId().'/invite"]');

        $this->client->submitForm('Save', ['user[lastName]' => 'Garnier-Roy', 'user[type]' => 'internal', 'user[company]' => '']);
        self::assertResponseRedirects();
        $ines = $this->entity(User::class, ['email' => 'ines@natura.test']) ?? self::fail('Inès vanished');
        self::assertSame('Inès Garnier-Roy', $ines->getFullName());
        self::assertTrue($ines->isInternal(), 'one form, one save');
        self::assertNull($ines->getCompany(), 'an emptied company is cleared');
    }

    public function testAnInvitationIsFollowedToChooseThePassword(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create and invite', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@natura.test',
            'user[type]' => 'external',
        ]);
        $link = self::passwordLink();

        $this->client->request('GET', '/logout');
        $this->client->request('GET', $link);
        self::assertResponseRedirects('/password/set', message: 'the token leaves the address');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Choose your password');
        self::assertSelectorTextContains('main', 'ines@natura.test');

        $this->client->submitForm('Set my password', ['password_set[password][first]' => 'a long password', 'password_set[password][second]' => 'a long password']);
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        $this->client->submitForm('Sign in', ['email' => 'ines@natura.test', 'password' => 'a long password']);
        self::assertResponseRedirects('/');
    }

    public function testAnInvitationIsResent(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create and invite', [
            'user[firstName]' => 'Inès',
            'user[lastName]' => 'Garnier',
            'user[email]' => 'ines@natura.test',
            'user[type]' => 'external',
        ]);
        $firstLink = self::passwordLink();
        $ines = $this->entity(User::class, ['email' => 'ines@natura.test']) ?? self::fail('no account');

        $this->client->followRedirect();
        $this->client->submitForm('Resend the invitation');
        self::assertResponseRedirects('/admin/users/'.$ines->getId());
        self::assertEmailCount(2, message: 'the invitation, then the one sent again');
        $secondLink = self::passwordLink();
        self::assertNotSame($firstLink, $secondLink);

        $thomas = $this->entity(User::class, ['email' => 'thomas.girard@cairn.test']) ?? self::fail('no Thomas');
        $thomas->setPassword('hashed');
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->request('GET', '/admin/users/'.$thomas->getId());
        self::assertSelectorNotExists('form[action$="/invite"]', 'an account with a password is not invited again');

        $visitor = $this->client;
        $visitor->request('GET', '/logout');
        $visitor->request('GET', $firstLink);
        $visitor->followRedirect();
        self::assertSelectorTextContains('h1', 'This link cannot be used', 'the new link replaces the old one');
        $visitor->request('GET', $secondLink);
        $visitor->followRedirect();
        self::assertSelectorTextContains('h1', 'Choose your password');
    }

    public function testTheSecondFactorOfAnAccountIsResetByAnAdministrator(): void
    {
        $thomas = $this->entity(User::class, ['email' => 'thomas.girard@cairn.test']) ?? self::fail('no Thomas');
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/users/'.$thomas->getId());
        self::assertSelectorNotExists('form[action$="/two-factor/reset"]', 'nothing to reset yet');

        $this->enableTwoFactorOn($thomas, self::totpSecret());
        $this->client->request('GET', '/admin/users');
        self::assertSelectorTextContains('tbody [data-test="two-factor-badge"]', '2FA');
        $this->client->request('GET', '/admin/users/'.$thomas->getId());
        self::assertSelectorTextContains('[data-test="two-factor-badge"]', '2FA');
        $this->client->submitForm('Reset');

        self::assertResponseRedirects('/admin/users/'.$thomas->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'The second factor of Thomas Girard was reset.');
        self::assertSelectorNotExists('form[action$="/two-factor/reset"]');
        $thomas = $this->entity(User::class, ['email' => 'thomas.girard@cairn.test']) ?? self::fail('no Thomas');
        self::assertFalse($thomas->hasTotp());
    }

    public function testNobodyResetsTheirOwnSecondFactor(): void
    {
        $julie = $this->scene->user('julie.admin@cairn.test');
        $this->enableTwoFactorOn($julie, self::totpSecret());
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/users/'.$julie->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action$="/two-factor/reset"]', 'one\'s own is turned off with a code');
        $this->client->request('POST', '/admin/users/'.$julie->getId().'/two-factor/reset');

        self::assertResponseStatusCodeSame(403);
        $julie = $this->entity(User::class, ['email' => 'julie.admin@cairn.test']) ?? self::fail('no Julie');
        self::assertTrue($julie->hasTotp());
    }

    public function testOnlyAnAdministratorResetsASecondFactor(): void
    {
        $nadia = $this->entity(User::class, ['email' => 'nadia.benali@cairn.test']) ?? self::fail('no Nadia');
        $this->enableTwoFactorOn($nadia, self::totpSecret());
        $this->client->loginUser($this->scene->user('thomas.girard@cairn.test'));

        $this->client->request('POST', '/admin/users/'.$nadia->getId().'/two-factor/reset');

        self::assertResponseStatusCodeSame(403);
        $nadia = $this->entity(User::class, ['email' => 'nadia.benali@cairn.test']) ?? self::fail('no Nadia');
        self::assertTrue($nadia->hasTotp());
    }

    public function testMakingAManagerExternalIsRefusedWithTheReason(): void
    {
        $this->signInAsAdmin();
        $thomas = $this->scene->user('thomas.girard@cairn.test');
        $crawler = $this->client->request('GET', '/admin/users/'.$thomas->getId());

        $form = $crawler->filter('form[name="user"]')->form(['user[type]' => 'external']);
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="user"]', 'Thomas Girard holds roles for internal users only (Maison Dupont)');
    }

    public function testTheLastAdministratorIsNotDeactivated(): void
    {
        $this->signInAsAdmin();
        $julie = $this->scene->user('julie.admin@cairn.test');
        $this->client->request('GET', '/admin/users/'.$julie->getId());

        $this->client->submitForm('Save', ['user[active]' => false]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="user"]', 'At least one active administrator must remain.');
    }

    public function testAnAccountWithoutHistoryIsDeletedAndTheOthersAreNot(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/users/new');
        $this->client->submitForm('Create', ['user[firstName]' => 'Zoe', 'user[lastName]' => 'Mistake', 'user[email]' => 'zoe@cairn.test']);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'it can be deleted for good');

        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/admin/users');
        self::assertNull($this->entity(User::class, ['email' => 'zoe@cairn.test']));

        $this->client->request('GET', \sprintf('/admin/users/%d', $this->scene->user('claire.martin@maison-dupont.test')->getId()));
        self::assertSelectorNotExists('form[action$="/delete"]');
        self::assertSelectorTextContains('main', 'deactivate it to close its access');

        $this->client->request('GET', \sprintf('/admin/users/%d', $this->scene->user('julie.admin@cairn.test')->getId()));
        self::assertSelectorTextNotContains('main', 'Delete the account');
    }

    public function testATeamIsCreatedRenamedAndDeleted(): void
    {
        $this->signInAsAdmin();
        $this->client->request('GET', '/admin/teams');
        self::assertSelectorTextContains('main', 'No team yet.');

        $this->client->clickLink('New team');
        $this->client->submitForm('Create', ['team[name]' => 'Hosting', 'team[nature]' => 'internal']);
        $team = $this->entity(Team::class, ['name' => 'Hosting']);
        self::assertNotNull($team);
        self::assertTrue($team->isInternal());
        self::assertResponseRedirects('/admin/teams/'.$team->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Hosting');
        self::assertSelectorTextContains('h1', 'Internal');
        self::assertSelectorTextContains('main', 'This team has no member yet.');

        $this->client->request('GET', '/admin/teams/new');
        $this->client->submitForm('Create', ['team[name]' => 'hosting', 'team[nature]' => 'external']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="team"]', 'A team is already named hosting.');

        $this->client->request('GET', '/admin/teams/'.$team->getId().'/access');
        self::assertSelectorTextContains('main', 'This team has no access yet.');
        self::getContainer()->get(CommandBus::class)->dispatch(new GrantTeamAccess((int) $team->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $this->client->request('GET', '/admin/teams/'.$team->getId().'/access');
        self::assertSelectorTextContains('main', 'The whole Maison Dupont workspace');
        $this->client->request('GET', '/admin/workspaces/MD/access');
        self::assertSelectorTextContains('main', 'Hosting');

        $this->client->request('GET', '/admin/teams/'.$team->getId().'/settings');
        self::assertSelectorNotExists('[name="team[nature]"]', 'whether it is internal does not change');
        $this->client->submitForm('Save', ['team[name]' => 'Hosting & networks']);
        self::assertResponseRedirects('/admin/teams/'.$team->getId().'/settings');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Hosting & networks');

        $this->client->request('GET', '/admin/teams');
        self::assertSelectorTextContains('main', 'Hosting & networks');
        self::assertSelectorTextContains('main', 'No member');

        $this->client->submit($crawler->filter('form[action$="/delete"]')->form());
        self::assertResponseRedirects('/admin/teams');
        self::assertNull($this->entity(Team::class, ['name' => 'Hosting & networks']), 'its accesses went with it');
    }

    public function testMakingAMemberOfAnInternalTeamExternalIsRefusedWithTheReason(): void
    {
        $this->signInAsAdmin();
        $marc = $this->scene->user('marc.petit@cairn.test');
        $commands = self::getContainer()->get(CommandBus::class);
        $team = $commands->dispatch(new CreateTeam('Hosting', true));
        \assert($team instanceof Team);
        $commands->dispatch(new AddTeamMember((int) $team->getId(), (int) $marc->getId()));
        $commands->dispatch(new RevokeMembership((int) self::getContainer()->get(MembershipRepository::class)->findByUser($marc)[0]->getId()));

        $crawler = $this->client->request('GET', '/admin/users/'.$marc->getId());
        $this->client->submit($crawler->filter('form[name="user"]')->form(['user[type]' => 'external']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="user"]', 'Marc Petit is a member of internal teams (Hosting)');
    }

    public function testTheUsersAreFiltered(): void
    {
        $this->signInAsAdmin();

        $this->client->request('GET', '/admin/users?type[]=external&q=martin');

        self::assertSelectorTextContains('tbody', 'Claire Martin');
        self::assertSelectorTextNotContains('tbody', 'Thomas Girard');
    }

    private function signInAsAdmin(): void
    {
        $this->client->loginUser($this->scene->user('julie.admin@cairn.test'));
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $criteria
     *
     * @return T|null
     */
    private function entity(string $class, array $criteria): ?object
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository($class)->findOneBy($criteria);
    }
}
