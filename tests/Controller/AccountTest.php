<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\MailGrouping;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The pages of one's own account: the profile and its picture, the
 * grouping of the mails, the password. Claire is a client: they are for
 * everyone, and who she is stays the administration's.
 */
final class AccountTest extends WebTestCase
{
    /** A 1×1 PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private KernelBrowser $client;
    private User $claire;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->claire = ShopScene::load(self::getContainer())->user('claire.martin@maison-dupont.test');
    }

    /**
     * The account as the database has it now: each request has its own entity manager.
     */
    private function account(): User
    {
        $user = self::getContainer()->get(UserRepository::class)->find($this->claire->getId());
        self::assertNotNull($user);

        return $user;
    }

    public function testTheProfileShowsWhoTheyAreWithoutLettingThemChangeIt(): void
    {
        $this->client->loginUser($this->claire);

        $this->client->request('GET', '/account');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Profile');
        self::assertSelectorExists('header a[href="/account"]', 'The user menu leads to the account.');
        self::assertSelectorExists('aside a[href="/account/notifications"]');
        self::assertSelectorExists('aside a[href="/account/security"]');
        self::assertSelectorNotExists('aside a[href="/account/tokens"]', 'The API is for internal users.');
        self::assertSelectorTextContains('[data-test="account-identity"]', 'Claire Martin');
        self::assertSelectorTextContains('[data-test="account-identity"]', 'claire.martin@maison-dupont.test');
        self::assertSelectorTextContains('[data-test="account-identity"]', 'managed by an administrator');
        self::assertSelectorNotExists('[data-test="account-identity"] input');
    }

    public function testTheMailGroupingIsChosen(): void
    {
        $this->client->loginUser($this->claire);

        $crawler = $this->client->request('GET', '/account/notifications');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="mail_settings[mailGrouping]"][value="project"][checked]', 'One email per project unless chosen otherwise.');

        $this->client->submit($crawler->selectButton('Save')->form(), ['mail_settings[mailGrouping]' => 'issue']);
        self::assertResponseRedirects('/account/notifications');
        $this->client->followRedirect();

        self::assertSame(MailGrouping::Issue, $this->account()->getMailGrouping());
        self::assertSelectorExists('input[name="mail_settings[mailGrouping]"][value="issue"][checked]');
    }

    public function testNoMailCanBeChosen(): void
    {
        $this->client->loginUser($this->claire);

        $crawler = $this->client->request('GET', '/account/notifications');
        self::assertSelectorTextContains('form', 'No email');

        $this->client->submit($crawler->selectButton('Save')->form(), ['mail_settings[mailGrouping]' => 'none']);
        self::assertResponseRedirects('/account/notifications');

        self::assertSame(MailGrouping::None, $this->account()->getMailGrouping());
    }

    public function testAPictureIsChosenShownAndRemoved(): void
    {
        $this->client->loginUser($this->claire);
        $picture = tempnam(sys_get_temp_dir(), 'avatar');
        self::assertIsString($picture);
        file_put_contents($picture, base64_decode(self::PNG, true));

        $crawler = $this->client->request('GET', '/account');
        self::assertSelectorExists('span[data-test="account-avatar"]', 'Initials until a picture is chosen.');
        $form = $crawler->filter('form[action="/account/avatar"]')->first()->form();
        $this->client->submit($form, ['avatar' => $picture]);
        self::assertResponseRedirects('/account');
        $crawler = $this->client->followRedirect();

        $name = $this->account()->getAvatar();
        self::assertNotNull($name);
        self::assertSame('/avatars/'.$name, $crawler->filter('img[data-test="account-avatar"]')->attr('src'));
        self::assertGreaterThan(0, $crawler->filter('header img[src="/avatars/'.$name.'"]')->count(), 'The header shows it too.');
        $this->client->request('GET', '/avatars/'.$name);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');

        $crawler = $this->client->request('GET', '/account');
        $this->client->submit($crawler->selectButton('Remove')->form());
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();
        self::assertNull($this->account()->getAvatar());
        self::assertSelectorExists('span[data-test="account-avatar"]');
        $this->client->request('GET', '/avatars/'.$name);
        self::assertResponseStatusCodeSame(404, 'The file went with the picture.');
    }

    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        $this->client->loginUser($this->claire);
        $text = tempnam(sys_get_temp_dir(), 'avatar');
        self::assertIsString($text);
        file_put_contents($text, '<script>alert(1)</script>');

        $crawler = $this->client->request('GET', '/account');
        $form = $crawler->filter('form[action="/account/avatar"]')->first()->form();
        $this->client->submit($form, ['avatar' => $text]);
        self::assertResponseRedirects('/account');
        $this->client->followRedirect();

        self::assertNull($this->account()->getAvatar());
        self::assertSelectorTextContains('body', 'Choose a PNG, JPEG or WebP image');
    }

    public function testThePasswordChangesWithTheCurrentOneAndTheSessionGoesOn(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->claire->setPassword($hasher->hashPassword($this->claire, 'the old password'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($this->claire);

        $crawler = $this->client->request('GET', '/account/security');
        $this->client->submit($crawler->selectButton('Change password')->form(), [
            'password_change[currentPassword]' => 'not the password',
            'password_change[newPassword][first]' => 'a new password',
            'password_change[newPassword][second]' => 'a new password',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'The current password is incorrect.');
        self::assertTrue($hasher->isPasswordValid($this->account(), 'the old password'));

        $this->client->submit($crawler->selectButton('Change password')->form(), [
            'password_change[currentPassword]' => 'the old password',
            'password_change[newPassword][first]' => 'a new password',
            'password_change[newPassword][second]' => 'a new password',
        ]);
        self::assertResponseRedirects('/account/security');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful('Still signed in on this session.');
        self::assertTrue($hasher->isPasswordValid($this->account(), 'a new password'));
    }

    public function testThePasswordCannotBeGuessedAtWill(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->claire->setPassword($hasher->hashPassword($this->claire, 'the old password'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->loginUser($this->claire);

        $form = $this->client->request('GET', '/account/security')->selectButton('Change password')->form();
        $guess = ['password_change[currentPassword]' => 'a guess', 'password_change[newPassword][first]' => 'a new password', 'password_change[newPassword][second]' => 'a new password'];
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->client->submit($form, $guess);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->submit($form, ['password_change[currentPassword]' => 'the old password'] + $guess);
        self::assertResponseRedirects('/account/security');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Too many attempts');
        self::assertTrue($hasher->isPasswordValid($this->account(), 'the old password'), 'even the right password waits');
    }
}
