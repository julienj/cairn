<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Command\User;

use App\Entity\Enum\ActivityKind;
use App\Entity\Enum\AuthMethod;
use App\Entity\Enum\MailGrouping;
use App\Entity\PasswordToken;
use App\Entity\User;
use App\Message\Command\User\ChangePassword;
use App\Message\Command\User\CreateUser;
use App\Message\Command\User\InviteUser;
use App\Message\Command\User\RequestPasswordReset;
use App\Message\Command\User\ResetPassword;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Repository\MembershipRepository;
use App\Security\PasswordLinkException;
use App\Security\PasswordTokens;
use App\Tests\Config\Fixture\DefinitionFactory;
use App\Tests\KernelHelpers;
use App\Tests\Security\AccessFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The account commands: CreateUser, UpdateUser, InviteUser,
 * RequestPasswordReset and ResetPassword. Test roles: project_manager and
 * developer are internal only, client is not. Mails are sent on the spot
 * in the test environment, so they can be counted.
 */
final class UserHandlersTest extends KernelTestCase
{
    use KernelHelpers;
    use MailerAssertionsTrait;

    private CommandBus $commands;
    private AccessFixtures $fixtures;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new AccessFixtures(self::getContainer()->get(EntityManagerInterface::class));
    }

    public function testAnAccountIsCreatedWithoutAPasswordAndWithACompany(): void
    {
        $user = $this->commands->dispatch(new CreateUser('Ines@Vallier.test', 'Inès', 'Garnier', company: ' Vallier '));

        self::assertInstanceOf(User::class, $user);
        self::assertSame('ines@vallier.test', $user->getEmail());
        self::assertFalse($user->hasPassword(), 'the invitation gives the password');
        self::assertSame('Vallier', $user->getCompany());
        self::assertFalse($user->isInternal());
    }

    public function testWithoutAnIdentityProviderNoAccountIsToldToSignInThroughOne(): void
    {
        $claire = $this->fixtures->user('claire@client.test');

        try {
            $this->commands->dispatch(new CreateUser('ines@agency.test', 'Inès', 'Garnier', authMethod: AuthMethod::Sso));
            self::fail('There is no provider to sign in through.');
        } catch (UserException $e) {
            self::assertStringContainsString('no identity provider', $e->getMessage());
        }

        try {
            $this->commands->dispatch(new UpdateUser((int) $claire->getId(), authMethod: AuthMethod::Sso));
            self::fail('There is no provider to sign in through.');
        } catch (UserException $e) {
            self::assertStringContainsString('no identity provider', $e->getMessage());
        }

        // An account left on a provider that is gone is still taken back to a password.
        $claire->signInWithSso();
        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), authMethod: AuthMethod::Password));
        self::assertTrue($claire->awaitsPassword());
    }

    public function testTheConsoleStillGivesAPassword(): void
    {
        $user = $this->commands->dispatch(new CreateUser('admin@agency.test', 'Julie', 'Morel', admin: true, plainPassword: 'correct horse'));

        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isAdmin());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'correct horse'));
    }

    public function testAManagerCreatesAnExternalAccountFromTheirProjectOnly(): void
    {
        $thomas = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($thomas, $this->fixtures->workspace, 'project_manager');
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnProject($nadia, $this->fixtures->shop, 'developer');
        $shop = (int) $this->fixtures->shop->getId();

        $user = $this->commands->dispatch(new CreateUser('paul@client.test', 'Paul', 'Roussel', actorId: $thomas->getId(), projectId: $shop));
        self::assertInstanceOf(User::class, $user);
        self::assertFalse($user->isInternal());

        try {
            $this->commands->dispatch(new CreateUser('yann@agency.test', 'Yann', 'Leroy', internal: true, actorId: $thomas->getId(), projectId: $shop));
            self::fail('A manager creates external accounts only.');
        } catch (UserException $e) {
            self::assertStringContainsString('external', $e->getMessage());
        }

        try {
            $this->commands->dispatch(new CreateUser('remi@client.test', 'Rémi', 'Colin', actorId: $nadia->getId(), projectId: $shop));
            self::fail('A developer does not manage members.');
        } catch (AccessDeniedException) {
        }

        try {
            $this->commands->dispatch(new CreateUser('remi@client.test', 'Rémi', 'Colin', actorId: $thomas->getId(), projectId: (int) $this->fixtures->otherProject->getId()));
            self::fail('Not their project.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new CreateUser('remi@client.test', 'Rémi', 'Colin', actorId: $thomas->getId()));
    }

    public function testAnInvitationGoesToAnActiveAccountWithoutAPassword(): void
    {
        $claire = $this->fixtures->user('claire@client.test');

        $this->commands->dispatch(new InviteUser((int) $claire->getId()));

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'to', 'claire@client.test');
        self::assertEmailHtmlBodyContains($email, '/password/set/');
        self::assertCount(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(PasswordToken::class)->findBy(['user' => $claire]));

        $this->commands->dispatch(new InviteUser((int) $claire->getId()));
        self::assertEmailCount(2, message: 'invited again, as often as needed');
        self::assertCount(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(PasswordToken::class)->findBy(['user' => $claire]), 'the new link replaces the previous one');

        $claire->deactivate();
        try {
            $this->commands->dispatch(new InviteUser((int) $claire->getId()));
            self::fail('A deactivated account is not invited.');
        } catch (UserException $e) {
            self::assertStringContainsString('deactivated', $e->getMessage());
        }

        $claire->reactivate();
        $claire->setPassword('hashed');
        try {
            $this->commands->dispatch(new InviteUser((int) $claire->getId()));
            self::fail('An account with a password is not invited.');
        } catch (UserException $e) {
            self::assertStringContainsString('already has a password', $e->getMessage());
        }
        self::assertEmailCount(2);
    }

    public function testAManagerInvitesADirectMemberOfTheirProject(): void
    {
        $thomas = $this->fixtures->user('thomas@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($thomas, $this->fixtures->workspace, 'project_manager');
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnProject($nadia, $this->fixtures->shop, 'developer');
        $claire = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($claire, $this->fixtures->workspace, 'client');
        $paul = $this->fixtures->user('paul@client.test');
        $this->fixtures->grantOnProject($paul, $this->fixtures->shop, 'client');
        $shop = (int) $this->fixtures->shop->getId();

        $this->commands->dispatch(new InviteUser((int) $paul->getId(), $thomas->getId(), $shop));
        self::assertEmailCount(1);

        try {
            $this->commands->dispatch(new InviteUser((int) $claire->getId(), $thomas->getId(), $shop));
            self::fail('Claire holds her role on the workspace, not on the project.');
        } catch (UserException $e) {
            self::assertStringContainsString('no role on', $e->getMessage());
        }

        try {
            $this->commands->dispatch(new InviteUser((int) $paul->getId(), $nadia->getId(), $shop));
            self::fail('A developer does not manage members.');
        } catch (AccessDeniedException) {
        }

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new InviteUser((int) $paul->getId(), $thomas->getId()));
    }

    public function testAResetLinkGoesToAnActiveAccountAndSaysNothingOtherwise(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $claire->setPassword('hashed');
        $paul = $this->fixtures->user('paul@client.test');
        $paul->setPassword('hashed');
        $paul->deactivate();
        $this->fixtures->user('ines@client.test');
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->commands->dispatch(new RequestPasswordReset('nobody@client.test'));
        $this->commands->dispatch(new RequestPasswordReset('paul@client.test'));
        self::assertEmailCount(0, message: 'unknown or deactivated: nothing');

        $this->commands->dispatch(new RequestPasswordReset('ines@client.test'));
        self::assertEmailCount(1, message: 'a lost invitation is replaced the same way');

        $this->commands->dispatch(new RequestPasswordReset('Claire@Client.test'));
        self::assertEmailCount(2);

        $this->commands->dispatch(new RequestPasswordReset('claire@client.test'));
        self::assertEmailCount(2, message: 'asked again too soon: nothing, quietly');
    }

    public function testResetPasswordConsumesTheToken(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $claire->setPassword('hashed');
        $token = self::getContainer()->get(PasswordTokens::class)->issueReset($claire);

        try {
            $this->commands->dispatch(new ResetPassword(substr($token, 0, -1).('a' === substr($token, -1) ? 'b' : 'a'), 'a new password'));
            self::fail('A tampered token is refused.');
        } catch (PasswordLinkException $e) {
            self::assertStringContainsString('invalid', $e->getMessage());
        }

        $this->commands->dispatch(new ResetPassword($token, 'a new password'));
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($claire, 'a new password'));

        $this->expectException(PasswordLinkException::class);
        $this->commands->dispatch(new ResetPassword($token, 'another password'));
    }

    public function testSettingAPasswordEndsEveryOtherLinkOfTheAccount(): void
    {
        $ines = $this->fixtures->user('ines@client.test');
        $tokens = self::getContainer()->get(PasswordTokens::class);
        $invitation = $tokens->issueInvitation($ines);
        // The invitation was sent a while ago: a reset is refused while a link is that recent.
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('UPDATE '.PasswordToken::class.' t SET t.requestedAt = :then')->execute(['then' => new \DateTimeImmutable('-1 day')]);
        array_map($entityManager->refresh(...), $entityManager->getRepository(PasswordToken::class)->findAll());
        $reset = $tokens->issueReset($ines);

        $this->commands->dispatch(new ResetPassword($reset, 'a new password'));

        $this->expectException(PasswordLinkException::class);
        $this->commands->dispatch(new ResetPassword($invitation, 'the password of whoever kept the older mail'));
    }

    public function testOnlyWhatIsGivenChanges(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $claire->changeCompany('Maison Dupont');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), lastName: 'Dupont'));

        self::assertSame('Claire Dupont', $claire->getFullName());
        self::assertSame('claire@client.test', $claire->getEmail());
        self::assertSame('Maison Dupont', $claire->getCompany());
        self::assertFalse($claire->isInternal());
        self::assertTrue($claire->isActive());

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), company: ''));
        self::assertNull($claire->getCompany(), 'an empty company clears it');
    }

    public function testTheEmailStaysUnique(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $this->fixtures->user('paul@client.test');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), email: 'Claire.Dupont@Client.test'));
        self::assertSame('claire.dupont@client.test', $claire->getEmail());

        $this->expectException(UserException::class);
        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), email: 'PAUL@client.test'));
    }

    public function testAnInternalUserBecomesExternalOnlyWithoutInternalRoles(): void
    {
        $nadia = $this->fixtures->user('nadia@agency.test', internal: true);
        $this->fixtures->grantOnWorkspace($nadia, $this->fixtures->workspace, 'developer');

        try {
            $this->commands->dispatch(new UpdateUser((int) $nadia->getId(), firstName: 'Nadia B.', internal: false));
            self::fail('A developer cannot become external.');
        } catch (UserException $e) {
            self::assertStringContainsString('Maison Dupont', $e->getMessage());
        }
        self::assertTrue($nadia->isInternal());
        self::assertSame('Nadia', $nadia->getFirstName(), 'nothing changes when one rule refuses');

        self::getContainer()->get(MembershipRepository::class)->findByUser($nadia)[0]->changeRole(DefinitionFactory::roleRow('client', self::getContainer()->get(EntityManagerInterface::class)));
        $this->commands->dispatch(new UpdateUser((int) $nadia->getId(), internal: false));
        self::assertFalse($nadia->isInternal());
    }

    public function testAnAdministratorIsInternal(): void
    {
        $this->fixtures->user('admin@agency.test', admin: true);
        $claire = $this->fixtures->user('claire@client.test');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), admin: true));
        self::assertTrue($claire->isAdmin());
        self::assertTrue($claire->isInternal());

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), internal: false));
        self::assertFalse($claire->isAdmin(), 'an external user is no administrator');

        $this->expectException(ValidationFailedException::class);
        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), internal: false, admin: true));
    }

    public function testTheLastActiveAdministratorStaysOne(): void
    {
        $admin = $this->fixtures->user('admin@agency.test', admin: true);

        foreach ([new UpdateUser((int) $admin->getId(), admin: false), new UpdateUser((int) $admin->getId(), active: false)] as $command) {
            try {
                $this->commands->dispatch($command);
                self::fail('The last administrator must remain.');
            } catch (UserException $e) {
                self::assertStringContainsString('last active administrator', $e->getMessage());
            }
        }

        $this->fixtures->user('other-admin@agency.test', admin: true);
        $this->commands->dispatch(new UpdateUser((int) $admin->getId(), active: false));
        self::assertFalse($admin->isActive());
    }

    public function testADeactivatedAccountKeepsItsMemberships(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $this->fixtures->grantOnWorkspace($claire, $this->fixtures->workspace, 'client');

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), active: false));
        self::assertFalse($claire->isActive());
        self::assertCount(1, self::getContainer()->get(MembershipRepository::class)->findByUser($claire));

        $this->commands->dispatch(new UpdateUser((int) $claire->getId(), active: true));
        self::assertTrue($claire->isActive());
    }

    public function testSomeoneChangesTheMailGroupingOfTheirOwnAccountAndNothingElse(): void
    {
        $claire = $this->fixtures->user('claire@client.test');
        $paul = $this->fixtures->user('paul@client.test');
        $claireId = (int) $claire->getId();

        $this->commands->dispatch(new UpdateUser($claireId, mailGrouping: MailGrouping::Issue, actorId: $claireId));
        self::assertSame(MailGrouping::Issue, $claire->getMailGrouping());
        $this->commands->dispatch(new UpdateUser($claireId, hiddenActivity: [ActivityKind::Code, ActivityKind::Code], actorId: $claireId));
        self::assertSame([ActivityKind::Code], $claire->getHiddenActivity(), 'each kind once');

        foreach ([
            'the hidden activity of someone else' => new UpdateUser((int) $paul->getId(), hiddenActivity: [ActivityKind::Changes], actorId: $claireId),
            'the account of someone else' => new UpdateUser((int) $paul->getId(), mailGrouping: MailGrouping::Issue, actorId: $claireId),
            'their name' => new UpdateUser($claireId, firstName: 'Clara', actorId: $claireId),
            'their email' => new UpdateUser($claireId, email: 'clara@client.test', actorId: $claireId),
            'what they are' => new UpdateUser($claireId, internal: true, actorId: $claireId),
        ] as $what => $command) {
            try {
                $this->commands->dispatch($command);
                self::fail(\sprintf('Nobody changes %s.', $what));
            } catch (AccessDeniedException) {
            }
        }
        self::assertSame('Claire', $claire->getFirstName());
        self::assertSame('claire@client.test', $claire->getEmail());
        self::assertFalse($claire->isInternal());
    }

    public function testThePasswordChangesWithTheCurrentOneOnly(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $claire = $this->fixtures->user('claire@client.test');
        $claire->setPassword($hasher->hashPassword($claire, 'the old password'));
        $claireId = (int) $claire->getId();

        try {
            $this->commands->dispatch(new ChangePassword($claireId, 'not the password', 'a new password'));
            self::fail('A wrong current password is refused.');
        } catch (UserException) {
        }
        self::assertTrue($hasher->isPasswordValid($claire, 'the old password'));

        $this->commands->dispatch(new ChangePassword($claireId, 'the old password', 'a new password'));
        self::assertTrue($hasher->isPasswordValid($claire, 'a new password'));
    }
}
