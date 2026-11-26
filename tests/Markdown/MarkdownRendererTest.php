<?php

declare(strict_types=1);

namespace App\Tests\Markdown;

use App\Entity\Team;
use App\Entity\User;
use App\Markdown\MarkdownRenderer;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * In the scene MD-SPT-2 is a public shop issue, MD-SPT-6 an internal task
 * that Claire, a client, may not see.
 */
final class MarkdownRendererTest extends KernelTestCase
{
    private MarkdownRenderer $renderer;
    private ShopScene $scene;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->renderer = self::getContainer()->get(MarkdownRenderer::class);
    }

    public function testMarkdownIsRenderedAndRawHtmlEscaped(): void
    {
        $html = $this->renderer->render("**Blocking**\n\n- one\n- two\n\n<script>alert(1)</script>\n\nA [link](javascript:alert(1))", $this->user('claire.martin@maison-dupont.test'), $this->scene->shop);

        self::assertStringContainsString('<strong>Blocking</strong>', $html);
        self::assertStringContainsString('<li>one</li>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('<a>link</a>', $html);
    }

    public function testLinksToOtherSitesOpenApartAndOnlyAttachmentsAreImages(): void
    {
        $html = $this->renderer->render("[doc](https://docs.example.test/a) [here](/MD/SPT/issues)\n\n![screenshot](/attachments/0f6e3e2a-1b2c-4d3e-8f90-a1b2c3d4e5f6/screenshot.png) ![pixel](https://tracker.example.test/p.gif)", $this->user('claire.martin@maison-dupont.test'), $this->scene->shop);

        self::assertStringContainsString('<a rel="noopener noreferrer" target="_blank" href="https://docs.example.test/a">doc</a>', $html);
        self::assertStringContainsString('<a href="/MD/SPT/issues">here</a>', $html);
        self::assertStringContainsString('<img src="/attachments/0f6e3e2a-1b2c-4d3e-8f90-a1b2c3d4e5f6/screenshot.png" alt="screenshot" />', $html);
        self::assertStringNotContainsString('<img src="https://tracker', $html, 'a remote image would log every reader');
        self::assertStringContainsString('https://tracker.example.test/p.gif', $html, 'left as text, nothing is lost');

        $html = $this->renderer->render('![out](/attachments/../logout)', $this->user('claire.martin@maison-dupont.test'), $this->scene->shop);
        self::assertStringNotContainsString('<img', $html, 'a path that leaves the attachments would make every reader call it');
    }

    public function testAbsoluteUrlsAreMadeForATextReadAwayFromTheSite(): void
    {
        $markdown = "[here](/MD/SPT/issues) and #MD-SPT-3\n\n![screenshot](/attachments/0f6e3e2a-1b2c-4d3e-8f90-a1b2c3d4e5f6/screenshot.png) [elsewhere](https://docs.example.test/a) [protocol](//cdn.example.test/x)";

        $html = $this->renderer->render($markdown, $this->user('nadia.benali@cairn.test'), $this->scene->shop, absoluteUrls: true);

        self::assertStringContainsString('href="http://localhost/MD/SPT/issues"', $html, 'a link typed as a path');
        self::assertStringContainsString('href="http://localhost/MD/SPT/issues/3"', $html, 'an issue reference');
        self::assertStringContainsString('src="http://localhost/attachments/0f6e3e2a-1b2c-4d3e-8f90-a1b2c3d4e5f6/screenshot.png"', $html, 'an attachment');
        self::assertStringContainsString('href="https://docs.example.test/a"', $html, 'another site is left alone');
        self::assertStringContainsString('href="//cdn.example.test/x"', $html, 'a protocol-relative URL too');
    }

    public function testAnAddressWrittenBareIsALink(): void
    {
        $html = $this->renderer->render("See https://docs.example.test/a?b=1#MD-SPT-2. Or www.example.test, or write to help@example.test\n\n`https://code.example.test` [already](https://docs.example.test/b)", $this->user('claire.martin@maison-dupont.test'), $this->scene->shop);

        self::assertStringContainsString('<a rel="noopener noreferrer" target="_blank" href="https://docs.example.test/a?b=1#MD-SPT-2">https://docs.example.test/a?b=1#MD-SPT-2</a>.', $html, 'the final dot is not of the address, and a reference inside it is left alone');
        self::assertStringContainsString('href="http://www.example.test">www.example.test</a>,', $html);
        self::assertStringContainsString('<a href="mailto:help@example.test">help@example.test</a>', $html);
        self::assertStringContainsString('<code>https://code.example.test</code>', $html, 'code stays text');
        self::assertStringContainsString('href="https://docs.example.test/b">already</a>', $html, 'a link written as one is not linked twice');
    }

    public function testReferencesInCodeStayText(): void
    {
        $html = $this->renderer->render("Type `#MD-SPT-2` or:\n\n```\n#MD-SPT-2 @[Tom](user:1)\n```", $this->user('nadia.benali@cairn.test'), $this->scene->shop);

        self::assertStringNotContainsString('<a', $html);
        self::assertStringNotContainsString('mention', $html);
        self::assertStringContainsString('<code>#MD-SPT-2</code>', $html);
    }

    public function testAnIssueReferenceIsALinkOnlyForWhoMaySeeTheIssue(): void
    {
        $markdown = 'Like #MD-SPT-2, see #MD-SPT-6 and #ZZ-ZZ-1, not abc#MD-SPT-2.';

        $client = $this->renderer->render($markdown, $this->user('claire.martin@maison-dupont.test'), $this->scene->shop);
        self::assertStringContainsString('<a class="issue-reference" href="/MD/SPT/issues/2"', $client);
        self::assertStringNotContainsString('/MD/SPT/issues/6', $client, 'a private issue cannot be probed');
        self::assertStringContainsString('see #MD-SPT-6 and #ZZ-ZZ-1', $client);
        self::assertStringContainsString('not abc#MD-SPT-2.', $client);

        $developer = $this->renderer->render($markdown, $this->user('nadia.benali@cairn.test'), $this->scene->shop);
        self::assertStringContainsString('href="/MD/SPT/issues/6"', $developer);
    }

    public function testAMentionShowsTheCurrentName(): void
    {
        $thomas = $this->user('thomas.girard@cairn.test');

        $html = $this->renderer->render(\sprintf('For @[Tom](user:%d) and @[Former](user:999999)', $thomas->getId()), $this->user('claire.martin@maison-dupont.test'), $this->scene->shop);

        self::assertStringContainsString('For <span class="mention">@Thomas Girard</span>', $html);
        self::assertStringContainsString('and @Former', $html, 'an unknown user stays text');
    }

    public function testATeamIsMentionedByItsCurrentNameWhereItHoldsARole(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $hosting = $commands->dispatch(new CreateTeam('Hosting', true));
        $support = $commands->dispatch(new CreateTeam('Support', true));
        self::assertInstanceOf(Team::class, $hosting);
        self::assertInstanceOf(Team::class, $support);
        $commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $markdown = \sprintf('For @[the hosts](team:%d), not @[the others](team:%d) nor @[nobody](team:999999)', $hosting->getId(), $support->getId());

        $html = $this->renderer->render($markdown, $this->user('claire.martin@maison-dupont.test'), $this->scene->shop);

        self::assertStringContainsString('For <span class="mention">@Hosting</span>', $html);
        self::assertStringContainsString('not @the others', $html, 'a team without a role on the project stays text');
        self::assertStringNotContainsString('Support', $html);
        self::assertStringContainsString('nor @nobody', $html);
        self::assertSame([$hosting->getId(), $support->getId(), 999999], $this->renderer->mentionedTeamIds($markdown));
        self::assertSame([], $this->renderer->mentionedUserIds($markdown), 'a team is not a person');
    }

    public function testOnlyAMemberOfTheProjectIsNamed(): void
    {
        // Marc has no role on the shop: his id, written by hand, tells nothing.
        $marc = $this->user('marc.petit@cairn.test');

        $html = $this->renderer->render(\sprintf('For @[someone](user:%d)', $marc->getId()), $this->user('claire.martin@maison-dupont.test'), $this->scene->shop);

        self::assertStringContainsString('For @someone', $html);
        self::assertStringNotContainsString('Marc', $html);
    }

    private function user(string $email): User
    {
        return $this->scene->user($email);
    }
}
