<?php

declare(strict_types=1);

namespace App\Tests\Controller\Widget;

use App\Entity\Attachment;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\WidgetToken;
use App\Message\Command\Project\UpdateProject;
use App\Message\Command\Widget\AddWidgetSite;
use App\Message\Command\Widget\LinkWidget;
use App\Message\Command\Widget\RedeemWidgetLink;
use App\Message\Command\Widget\RemoveWidgetSite;
use App\Message\CommandBus;
use App\MessageHandler\Command\Widget\WidgetException;
use App\Repository\IssueRepository;
use App\Repository\IssueTagRepository;
use App\Repository\ProjectRepository;
use App\Repository\WidgetSiteRepository;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The widget of the shop, shown on its staging site: Thomas manages
 * Maison Dupont, Nadia develops on the shop, Claire is its client, Paul
 * only reads it, Sophie is the client of another workspace. Bugs ask for
 * an environment; the shop has an internal category.
 */
final class WidgetTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private const string SITE = 'https://staging.maison-dupont.test';
    private const string CLAIRE = 'claire.martin@maison-dupont.test';
    private const string THOMAS = 'thomas.girard@cairn.test';
    /** What the browser says of a request the frame of the widget made. */
    private const array FROM_THE_FRAME = ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => 'http://localhost/widget/MD/SPT?site=https%3A%2F%2Fstaging.maison-dupont.test'];
    private const string FRAME = '/widget/MD/SPT?site=https%3A%2F%2Fstaging.maison-dupont.test';

    private KernelBrowser $client;
    private ShopScene $scene;
    private Project $shop;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
        $this->shop = $this->scene->issue('MD-SPT-1')->getProject();
        $this->commands()->dispatch(new AddWidgetSite((int) $this->scene->user(self::THOMAS)->getId(), (int) $this->shop->getId(), self::SITE));
    }

    // --- before anybody is known

    public function testAProjectTellsItsSitesAndNoOtherThatItKnowsThem(): void
    {
        $this->client->request('GET', '/widget/MD/SPT/site', server: ['HTTP_ORIGIN' => self::SITE]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::SITE);
        self::assertSame(['label' => 'Report a problem'], json_decode((string) $this->client->getResponse()->getContent(), true));

        foreach (['https://www.maison-dupont.test', 'null', ''] as $origin) {
            $this->client->request('GET', '/widget/MD/SPT/site', server: ['HTTP_ORIGIN' => $origin]);
            self::assertResponseStatusCodeSame(404, 'origin "'.$origin.'"');
            self::assertFalse($this->client->getResponse()->headers->has('Access-Control-Allow-Origin'));
        }

        // a project without that site has no widget there, like a project that does not exist: nothing tells them apart
        foreach (['/widget/MD/SPT', '/widget/MD/SPT?site=https%3A%2F%2Fwww.maison-dupont.test', '/widget/MD/INT?site=https%3A%2F%2Fstaging.maison-dupont.test', '/widget/MD/INT/site', '/widget/XX/YY?site=https%3A%2F%2Fstaging.maison-dupont.test'] as $address) {
            $this->client->request('GET', $address, server: ['HTTP_ORIGIN' => self::SITE]);
            self::assertResponseStatusCodeSame(404, $address);
        }
        foreach (['{}', '{"site":"https://www.maison-dupont.test"}', '"a text"'] as $body) {
            $this->client->request('POST', '/widget/MD/SPT/token', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
            self::assertResponseStatusCodeSame(404, $body);
        }
    }

    public function testTheFrameIsForTheSiteItWasAskedForAndHoldsNothingOfTheProject(): void
    {
        $this->commands()->dispatch(new AddWidgetSite((int) $this->scene->user(self::THOMAS)->getId(), (int) $this->shop->getId(), 'https://preprod.maison-dupont.test'));

        $this->client->request('GET', self::FRAME);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Security-Policy', 'frame-ancestors '.self::SITE, 'the other site of the project is not named');
        self::assertFalse($this->client->getResponse()->headers->has('X-Frame-Options'));
        self::assertSelectorExists(\sprintf('[data-controller="widget-frame"][data-widget-frame-project-value="MD-SPT"][data-widget-frame-site-value="%s"]', self::SITE));
        self::assertStringNotContainsString('preprod.maison-dupont.test', (string) $this->client->getResponse()->getContent());
        self::assertSelectorTextNotContains('body', 'Online shop');
        self::assertSame([], $this->client->getResponse()->headers->getCookies(), 'no session in the frame of another site');

        // the rest of the zone is fetched by the frame, never framed, and nothing else is
        $this->client->request('GET', '/widget/MD/SPT/report');
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Security-Policy', "frame-ancestors 'none'");
        $this->client->request('GET', '/login');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
    }

    // --- linking an account

    public function testLinkingStartsFromThePagesOfCairnOnly(): void
    {
        $challenge = hash('sha256', 'a secret');
        $query = '?site='.urlencode(self::SITE).'&challenge='.$challenge;

        foreach ([
            [],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site'] + self::FROM_THE_FRAME,
            ['HTTP_SEC_FETCH_SITE' => 'none'] + self::FROM_THE_FRAME,
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => 'http://localhost/MD/SPT/issues/1'],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => 'http://localhost/widget/MD/INT?site='.urlencode(self::SITE)],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_REFERER' => 'https://elsewhere.test/widget/MD/SPT'],
        ] as $headers) {
            $this->client->request('GET', '/MD/SPT/widget/connect'.$query, server: $headers);
            self::assertResponseStatusCodeSame(404, 'another site, an address typed or a link in a page of Cairn starts nothing: '.json_encode($headers));
        }
        foreach (['?site='.urlencode('https://www.maison-dupont.test').'&challenge='.$challenge, '?site='.urlencode(self::SITE).'&challenge=short'] as $bad) {
            $this->client->request('GET', '/MD/SPT/widget/connect'.$bad, server: self::FROM_THE_FRAME);
            self::assertResponseStatusCodeSame(404, $bad);
        }

        // nothing was started: the page of the link says so, and agrees to nothing
        $this->client->loginUser($this->scene->user(self::CLAIRE));
        $this->client->request('GET', '/MD/SPT/widget/link');
        self::assertSelectorTextContains('main', 'This link has expired');
        self::assertSelectorNotExists('main form');
    }

    public function testWhoeverIsNotSignedInSignsInThenLinksTheirAccount(): void
    {
        // The accounts of the scene have no password, and login throttling counts in a cache that outlives a test.
        $claire = $this->account(self::CLAIRE);
        $claire->setPassword(self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($claire, 'a password of the test'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $pair = $this->pair();
        $this->client->request('GET', '/MD/SPT/widget/connect?site='.urlencode(self::SITE).'&challenge='.$pair['challenge'], server: self::FROM_THE_FRAME);
        self::assertResponseRedirects('/MD/SPT/widget/link');
        $this->client->followRedirect();
        self::assertResponseRedirects('/login');
        self::assertNull($this->redeem($pair['verifier']), 'nobody agreed yet');

        $this->client->request('GET', '/MD/SPT/widget/link');
        $this->client->followRedirect();
        $this->client->submitForm('Sign in', ['email' => self::CLAIRE, 'password' => 'a password of the test']);
        self::assertResponseRedirects('http://localhost/MD/SPT/widget/link', null, 'back to where the sign-in page was asked from');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Link your Cairn account to this site?');
        self::assertSelectorTextContains('main', 'staging.maison-dupont.test');
        self::assertSelectorTextContains('main', 'Claire Martin');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertNull($this->redeem($pair['verifier']), 'reading the page is not agreeing');

        $this->client->request('GET', '/MD/SPT/widget/link');
        $this->client->submitForm('Link my account');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Your account is linked');

        $secret = $this->redeem($pair['verifier']);
        self::assertNotNull($secret);
        self::assertMatchesRegularExpression(WidgetToken::PATTERN, $secret);
        self::assertNull($this->redeem($pair['verifier']), 'an agreement is taken once');

        $token = self::getContainer()->get(EntityManagerInterface::class)->getRepository(WidgetToken::class)->findOneBy(['hash' => WidgetToken::hash($secret)]);
        self::assertNotNull($token);
        self::assertSame([self::CLAIRE, 'MD-SPT', self::SITE], [$token->getUser()->getEmail(), $token->getProject()->getIssuePrefix(), $token->getOrigin()]);
    }

    public function testOnlyWhoMayCreateIssuesLinksTheirAccount(): void
    {
        foreach (['paul.roussel@maison-dupont.test', 'sophie.bernard@travelia.test'] as $email) {
            $this->client->loginUser($this->scene->user($email));
            $this->client->request('GET', '/MD/SPT/widget/connect?site='.urlencode(self::SITE).'&challenge='.hash('sha256', $email), server: self::FROM_THE_FRAME);
            $this->client->followRedirect();
            self::assertResponseStatusCodeSame(403, $email);
        }

        $paul = $this->scene->user('paul.roussel@maison-dupont.test');
        try {
            $this->commands()->dispatch(new LinkWidget((int) $paul->getId(), (int) $this->shop->getId(), self::SITE, hash('sha256', 'x')));
            self::fail('Paul only reads the shop.');
        } catch (AccessDeniedException) {
        }
        $this->expectException(WidgetException::class);
        $this->commands()->dispatch(new LinkWidget((int) $this->scene->user(self::CLAIRE)->getId(), (int) $this->shop->getId(), 'https://www.maison-dupont.test', hash('sha256', 'x')));
    }

    public function testAnAgreementIsForItsProjectAndForAFewMinutes(): void
    {
        $claire = $this->scene->user(self::CLAIRE);
        $intranet = $this->intranet();

        $verifier = bin2hex(random_bytes(32));
        $this->commands()->dispatch(new LinkWidget((int) $claire->getId(), (int) $this->shop->getId(), self::SITE, hash('sha256', $verifier)));
        self::assertNull($this->commands()->dispatch(new RedeemWidgetLink((int) $intranet->getId(), $verifier)), 'the widget of another project gets nothing');
        self::assertNull($this->commands()->dispatch(new RedeemWidgetLink((int) $this->shop->getId(), $verifier)), 'and the agreement is gone');

        $verifier = bin2hex(random_bytes(32));
        $this->commands()->dispatch(new LinkWidget((int) $claire->getId(), (int) $this->shop->getId(), self::SITE, hash('sha256', $verifier)));
        self::getContainer()->get('cache.app')->clear();
        self::assertNull($this->commands()->dispatch(new RedeemWidgetLink((int) $this->shop->getId(), $verifier)), 'an agreement that is no longer kept gives nothing');
    }

    // --- the token

    public function testATokenSignsTheRequestsOfItsWidgetAndNoOther(): void
    {
        $secret = $this->link(self::CLAIRE);

        $this->widget('GET', '/widget/MD/SPT/report', $secret);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#widget-report form[name="report"]');

        foreach ([null, 'cairnw_'.str_repeat('0', 64), 'cairn_'.str_repeat('0', 64), 'nonsense'] as $wrong) {
            $this->widget('GET', '/widget/MD/SPT/report', $wrong);
            self::assertResponseStatusCodeSame(401, (string) $wrong);
        }

        // a token of the shop opens neither the widget of another project, nor the application, nor the API
        $this->commands()->dispatch(new AddWidgetSite((int) $this->account(self::THOMAS)->getId(), (int) $this->intranet()->getId(), self::SITE));
        $this->widget('GET', '/widget/MD/INT/report', $secret);
        self::assertResponseStatusCodeSame(401);
        $this->widget('GET', '/MD/SPT/issues', $secret);
        self::assertResponseRedirects('/login');
        $this->widget('GET', '/api/me', $secret);
        self::assertResponseStatusCodeSame(401);
    }

    public function testATokenStopsWithItsTimeItsSiteItsAccountOrItsRight(): void
    {
        $secret = $this->link(self::CLAIRE);

        static::mockTime('+29 days');
        $this->widget('GET', '/widget/MD/SPT/report', $secret);
        self::assertResponseIsSuccessful();
        static::mockTime('+2 days');
        $this->widget('GET', '/widget/MD/SPT/report', $secret);
        self::assertResponseStatusCodeSame(401, 'a month went by');

        $secret = $this->link(self::CLAIRE);
        $this->account(self::CLAIRE)->deactivate();
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->widget('GET', '/widget/MD/SPT/report', $secret);
        self::assertResponseStatusCodeSame(401, 'a deactivated account');
        $this->account(self::CLAIRE)->reactivate();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        // the project archived: the token still names Claire, who may no longer create anything there
        $this->commands()->dispatch(new UpdateProject((int) $this->shop->getId(), archived: true));
        $this->widget('GET', '/widget/MD/SPT/report', $secret);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', self::FRAME);
        self::assertResponseStatusCodeSame(404, 'and the widget is gone from its sites');
        $this->commands()->dispatch(new UpdateProject((int) $this->shop->getId(), archived: false));

        $site = self::getContainer()->get(WidgetSiteRepository::class)->findOneByOrigin($this->shop, self::SITE);
        self::assertNotNull($site);
        $this->commands()->dispatch(new RemoveWidgetSite((int) $this->scene->user(self::THOMAS)->getId(), (int) $site->getId()));
        $this->widget('GET', '/widget/MD/SPT/report', $secret);
        self::assertResponseStatusCodeSame(401, 'the site removed');
    }

    // --- reporting a problem

    public function testTheFormAsksWhatTheCategoryRequiresAndWhatTheReporterMayQualify(): void
    {
        $tag = $this->scene->tag('Front office');
        $bug = $this->tracker('Bug');
        $feature = $this->tracker('Feature');

        $crawler = $this->widget('GET', '/widget/MD/SPT/report', $this->link(self::CLAIRE));
        self::assertSame(['Bug', 'Feature'], $crawler->filter('select[name="report[tracker]"] option')->each(static fn ($option): string => $option->text()), 'the internal category is not offered to a client');
        self::assertSelectorExists('[name="report[fields][environment]"]', 'bugs require an environment');
        self::assertSelectorNotExists('[name="report[fields][url]"]', 'what a category only offers stays on the page of the issue');
        self::assertSelectorExists('[name="report[criticality]"]');
        self::assertSelectorExists(\sprintf('input[name="report[tags][]"][value="%d"]', $tag->getId()));
        self::assertSelectorNotExists('[name="report[private]"]', 'privacy is not a client\'s to set');
        self::assertSelectorTextContains('form[name="report"]', 'Everyone on the project will see this issue.');
        self::assertSelectorExists('[data-controller="widget-capture"] [role="dialog"] canvas', 'the screenshot is drawn on and cropped before it is attached');

        // another category: the form is drawn again for it with what was typed, and nothing is created
        $before = self::getContainer()->get(IssueRepository::class)->count([]);
        $this->widget('POST', '/widget/MD/SPT/report', $this->link(self::CLAIRE), ['report' => ['tracker' => (string) $feature->getId(), 'title' => 'A wish list', 'description' => ''], 'refresh' => '1', 'attachments' => ['2f1c1d1e-0b7a-4c58-9d0e-6a3b1f2c4d5e' => 'capture.png']]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(\sprintf('select[name="report[tracker]"] option[value="%d"][selected]', $feature->getId()));
        self::assertInputValueSame('report[title]', 'A wish list');
        self::assertSelectorNotExists('[name="report[fields][environment]"]');
        self::assertSelectorExists('[name="report[fields][goal]"]', 'features require a goal');
        self::assertSelectorExists('input[name="attachments[2f1c1d1e-0b7a-4c58-9d0e-6a3b1f2c4d5e]"][value="capture.png"]', 'the files sent so far stay with the form');
        self::assertSelectorNotExists('form[name="report"] [role="alert"]', 'drawing again is not sending: nothing is reproached');
        self::assertSame($before, self::getContainer()->get(IssueRepository::class)->count([]));

        $this->widget('GET', '/widget/MD/SPT/report', $this->link(self::THOMAS));
        self::assertSelectorExists('select[name="report[tracker]"] option:contains("Internal task")');
        self::assertSelectorExists('[name="report[private]"]');
        self::assertSelectorTextNotContains('form[name="report"]', 'Everyone on the project will see this issue.');
        $this->widget('POST', '/widget/MD/SPT/report', $this->link(self::THOMAS), ['report' => ['tracker' => (string) $this->tracker('Internal task')->getId()], 'refresh' => '1']);
        self::assertSelectorNotExists('[name="report[private]"]', 'an internal category is private whatever one chooses');
        self::assertSelectorTextContains('form[name="report"] footer', 'Private category: hidden from external users.');
        self::assertSame((string) $bug->getId(), $crawler->filter('select[name="report[tracker]"] option')->first()->attr('value'));
    }

    public function testAClientReportsAProblemWithItsScreenshotAndWhatTheSiteNoted(): void
    {
        $secret = $this->link(self::CLAIRE);
        $tag = $this->scene->tag('Front office');
        $bug = $this->tracker('Bug');

        $this->widget('POST', '/widget/MD/SPT/attachments', $secret, files: ['file' => self::picture('capture.png')]);
        self::assertResponseStatusCodeSame(201);
        $file = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('capture.png', $file['name']);

        $technical = [
            'page' => ['url' => self::SITE.'/lamps', 'title' => 'Pendant lamps'],
            'environment' => ['userAgent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:145.0) Gecko/20100101 Firefox/145.0', 'viewport' => ['width' => 1280, 'height' => 800], 'zoom' => 1.5, 'preferences' => ['colorScheme' => 'dark', 'reducedMotion' => true, 'pointer' => 'coarse']],
            'context' => ['version' => '2026.11.3-rc2'],
            'errors' => [['kind' => 'console', 'message' => 'Price not found', 'at' => '2026-11-24T10:14:07.000Z']],
            'network' => [
                ['kind' => 'ajax', 'method' => 'GET', 'url' => self::SITE.'/api/prices/halo', 'status' => 500, 'at' => '2026-11-24T10:14:06.000Z'],
                ['kind' => 'font', 'url' => self::SITE.'/fonts/brume.woff2', 'status' => 404, 'at' => '2026-11-24T10:14:05.000Z'],
                ['kind' => 'image', 'url' => self::SITE.'/images/halo.jpg', 'at' => '2026-11-24T10:14:07.000Z'],
            ],
            'performance' => ['ttfb' => 320, 'lcp' => 4800, 'cls' => 0.12],
            'pages' => [['url' => self::SITE.'/'], ['url' => self::SITE.'/lamps']],
            'cookies' => 'whatever else a page may send',
        ];
        $this->widget('POST', '/widget/MD/SPT/report/preview', $secret, content: (string) json_encode($technical));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Firefox 145 · Linux');
        self::assertSelectorTextContains('body', '2026.11.3-rc2');
        self::assertSelectorTextContains('body', 'Price not found');
        self::assertSelectorTextContains('body', 'Network · 3 failures');
        $network = $this->client->getCrawler()->filter('li')->each(static fn ($item): string => (string) preg_replace('/\s+/', ' ', trim($item->text())));
        self::assertSame([
            '10:14:05 font 404 '.self::SITE.'/fonts/brume.woff2',
            '10:14:06 Ajax 500 GET '.self::SITE.'/api/prices/halo',
            '10:14:07 image '.self::SITE.'/images/halo.jpg',
        ], array_values(array_filter($network, static fn (string $line): bool => 1 === preg_match('/^\d\d:\d\d:\d\d /', $line))), 'in the order it failed: its kind, its status, its address');
        self::assertSelectorTextContains('body', '150 %');
        self::assertSelectorTextContains('body', 'Server answer Good 320 ms');
        self::assertSelectorTextContains('body', 'Main content Poor 4.8 s');
        self::assertSelectorTextContains('body', 'Stability To improve 0.12');
        self::assertSelectorTextNotContains('body', 'Reactivity', 'what the browser did not measure is not shown');
        self::assertSelectorTextContains('body', 'Dark theme · Touch screen · Reduced motion');
        self::assertSelectorTextNotContains('body', 'whatever else');

        $form = ['tracker' => (string) $bug->getId(), 'title' => 'The price of Halo shows NaN', 'description' => 'The first lamp has no price.', 'criticality' => 'high', 'tags' => [(string) $tag->getId()], 'technical' => json_encode($technical), 'includeReport' => '1'];
        $this->widget('POST', '/widget/MD/SPT/report', $secret, ['report' => $form, 'attachments' => [$file['uuid'] => $file['name']]]);
        self::assertResponseStatusCodeSame(422, 'the environment a bug requires is missing');
        self::assertSelectorExists(\sprintf('input[name="attachments[%s]"]', $file['uuid']));

        $this->widget('POST', '/widget/MD/SPT/report', $secret, ['report' => $form + ['fields' => ['environment' => 'preprod']], 'attachments' => [$file['uuid'] => $file['name']]]);
        $issue = $this->lastIssue();
        self::assertResponseRedirects('/widget/MD/SPT/report/sent/'.$issue->getNumber(), 303);
        self::assertSame(['The price of Halo shows NaN', self::CLAIRE, Criticality::High, 'Bug', false], [$issue->getTitle(), $issue->getAuthor()->getEmail(), $issue->getCriticality(), $issue->getTracker()->getName(), $issue->isPrivate()]);
        self::assertSame(1, self::getContainer()->get(IssueTagRepository::class)->count(['issue' => $issue, 'tag' => $tag]));
        $attachment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Attachment::class)->findOneBy(['uuid' => $file['uuid']]);
        self::assertSame($issue->getId(), $attachment?->getIssue()?->getId(), 'the screenshot is the issue\'s');
        $report = $issue->getReport();
        self::assertNotNull($report);
        self::assertSame(self::SITE.'/lamps', $report['page']['url']);
        self::assertArrayNotHasKey('cookies', $report);

        $this->widget('GET', '/widget/MD/SPT/report/sent/'.$issue->getNumber(), $secret);
        self::assertSelectorTextContains('turbo-frame#widget-report', 'Issue '.$issue->getKey().' created');
        self::assertSelectorExists(\sprintf('a[href="http://localhost/MD/SPT/issues/%d"][target="_blank"]', $issue->getNumber()));

        // the report is read on the page of the issue, by whoever sees the issue
        foreach ([self::CLAIRE, 'nadia.benali@cairn.test'] as $email) {
            $this->client->loginUser($this->scene->user($email));
            $this->client->request('GET', '/MD/SPT/issues/'.$issue->getNumber());
            self::assertSelectorExists(\sprintf('a[href="/MD/SPT/issues/%d/report"][data-turbo-frame="modal"]', $issue->getNumber()), $email);
            $this->client->request('GET', \sprintf('/MD/SPT/issues/%d/report', $issue->getNumber()));
            self::assertSelectorTextContains('[role="dialog"]', 'Technical report');
            self::assertSelectorTextContains('[role="dialog"]', 'Price not found');
        }
        $this->client->request('GET', '/MD/SPT/issues/1');
        self::assertSelectorNotExists('a[href="/MD/SPT/issues/1/report"]', 'an issue written in Cairn has no report');
        $this->client->request('GET', '/MD/SPT/issues/1/report');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheReportIsLeftOutWhenTheReporterSaysSoAndPrivacyIsForWhoMaySetIt(): void
    {
        $bug = $this->tracker('Bug');
        $form = ['tracker' => (string) $bug->getId(), 'title' => 'No report', 'description' => 'Nothing of the site.', 'fields' => ['environment' => 'prod'], 'technical' => json_encode(['page' => ['url' => self::SITE.'/']])];

        $before = $this->lastIssue()->getId();
        $this->widget('POST', '/widget/MD/SPT/report', $this->link(self::CLAIRE), ['report' => $form + ['private' => '1']]);
        self::assertResponseStatusCodeSame(422, 'a field the form did not offer');
        self::assertSame($before, $this->lastIssue()->getId());

        $this->widget('POST', '/widget/MD/SPT/report', $this->link(self::CLAIRE), ['report' => $form]);
        self::assertResponseStatusCodeSame(303);
        self::assertNull($this->lastIssue()->getReport(), 'the box was not ticked');

        $this->widget('POST', '/widget/MD/SPT/report', $this->link(self::THOMAS), ['report' => $form + ['private' => '1', 'includeReport' => '1']]);
        self::assertResponseStatusCodeSame(303);
        $issue = $this->lastIssue();
        self::assertSame([self::THOMAS, true], [$issue->getAuthor()->getEmail(), $issue->isPrivate()]);
        self::assertNotNull($issue->getReport());

        // the report of a private issue is as private as the issue
        $this->client->loginUser($this->scene->user(self::CLAIRE));
        $this->client->request('GET', \sprintf('/MD/SPT/issues/%d/report', $issue->getNumber()));
        self::assertResponseStatusCodeSame(404);
    }

    // --- helpers

    /**
     * Links the account to the widget of the shop on its site, the way
     * the frame and its window do, and gives the secret of the token.
     */
    private function link(string $email): string
    {
        $pair = $this->pair();
        $this->client->loginUser($this->scene->user($email));
        $this->client->request('GET', '/MD/SPT/widget/connect?site='.urlencode(self::SITE).'&challenge='.$pair['challenge'], server: self::FROM_THE_FRAME);
        $this->client->followRedirect();
        $this->client->submitForm('Link my account');
        self::assertResponseIsSuccessful();
        // the widget has no session: nothing of this one must sign its requests
        $this->client->getCookieJar()->clear();

        return $this->redeem($pair['verifier']) ?? self::fail('The account was not linked.');
    }

    /**
     * @return array{verifier: string, challenge: string}
     */
    private function pair(): array
    {
        $this->client->request('POST', '/widget/MD/SPT/token', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['site' => self::SITE]));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        $pair = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(hash('sha256', $pair['verifier']), $pair['challenge']);

        return $pair;
    }

    private function redeem(string $verifier): ?string
    {
        $this->client->request('POST', '/widget/MD/SPT/token', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['site' => self::SITE, 'verifier' => $verifier]));
        if (204 === $this->client->getResponse()->getStatusCode()) {
            return null;
        }
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true)['token'];
    }

    /**
     * @param array<string, mixed>        $parameters
     * @param array<string, UploadedFile> $files
     */
    private function widget(string $method, string $address, ?string $secret, array $parameters = [], array $files = [], ?string $content = null): Crawler
    {
        return $this->client->request($method, $address, $parameters, $files, null === $secret ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$secret], $content);
    }

    /**
     * The account as the kernel of the moment holds it: each request boots another one.
     */
    private function account(string $email): User
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => $email]) ?? self::fail('No account '.$email);
    }

    private function intranet(): Project
    {
        return self::getContainer()->get(ProjectRepository::class)->findOneByKeys('MD', 'INT') ?? self::fail('No intranet.');
    }

    private function tracker(string $name): Tracker
    {
        return self::getContainer()->get(EntityManagerInterface::class)->getRepository(Tracker::class)->findOneBy(['project' => $this->shop, 'name' => $name]) ?? self::fail('No tracker '.$name);
    }

    private function lastIssue(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->getRepository(Issue::class)->findOneBy([], ['id' => 'DESC']) ?? self::fail('No issue.');
    }

    private function commands(): CommandBus
    {
        return self::getContainer()->get(CommandBus::class);
    }

    private static function picture(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'widget');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
