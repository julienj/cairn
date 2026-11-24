<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * With a Mercure hub, the list of a project listens to the topics its
 * viewer may hear: both audiences of the project for an internal user,
 * one for a client. The page carries the token that allows them, and
 * only them; no cookie is set.
 * Without a hub, which is how the other tests run, a list listens to
 * nothing.
 */
final class LiveUpdatesTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        // The hub is read from the environment when the kernel boots: one test runs without.
        $_ENV['MERCURE_URL'] = $_SERVER['MERCURE_URL'] = 'testWithoutAHubAListListensToNothing' === $this->name() ? '' : 'http://hub.test/.well-known/mercure';
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    protected function tearDown(): void
    {
        $_ENV['MERCURE_URL'] = $_SERVER['MERCURE_URL'] = '';
        parent::tearDown();
    }

    public function testAnInternalUserListensToBothAudiencesOfTheProject(): void
    {
        $this->signInAs('nadia.benali@cairn.test');
        $project = $this->scene->issue('MD-SPT-1')->getProject()->getId();

        $crawler = $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#issues[data-controller~="live-updates"]');
        self::assertCount(1, $frame);
        self::assertSame(["projects/$project", "projects/$project/internal"], $this->topicsOf((string) $frame->attr('data-live-updates-url-value')));
        self::assertSame(["projects/$project", "projects/$project/internal"], $this->topicsAllowed($frame->attr('data-live-updates-token-value')));
        self::assertSame([], $this->hubCookies(), 'the page holds its token: a cookie would be shared by every tab');
    }

    public function testAClientListensToWhatExternalUsersSeeOnly(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $project = $this->scene->issue('MD-SPT-1')->getProject()->getId();

        $crawler = $this->client->request('GET', '/MD/SPT/issues');

        $frame = $crawler->filter('turbo-frame#issues');
        self::assertSame(["projects/$project"], $this->topicsOf((string) $frame->attr('data-live-updates-url-value')));
        self::assertSame(["projects/$project"], $this->topicsAllowed($frame->attr('data-live-updates-token-value')));
    }

    public function testAListAcrossProjectsListensToNothing(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $crawler = $this->client->request('GET', '/issues/assigned');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-controller~="live-updates"]'));
        self::assertCount(0, $crawler->filter('[data-live-updates-token-value]'));
    }

    public function testWithoutAHubAListListensToNothing(): void
    {
        $this->signInAs('nadia.benali@cairn.test');

        $crawler = $this->client->request('GET', '/MD/SPT/issues');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#issues[data-controller~="list-view"]'));
        self::assertCount(0, $crawler->filter('[data-controller~="live-updates"]'));
        self::assertCount(0, $crawler->filter('[data-live-updates-token-value]'));
        self::assertSame([], $this->hubCookies());
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }

    /**
     * The topics an address of the hub subscribes to.
     *
     * @return list<string>
     */
    private function topicsOf(string $url): array
    {
        preg_match_all('/[?&]match=([^&]+)/', $url, $matches);

        return array_map(rawurldecode(...), $matches[1]);
    }

    /**
     * The topics a token lets its holder subscribe to.
     *
     * @return list<string>
     */
    private function topicsAllowed(?string $token): array
    {
        self::assertNotNull($token);
        $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/'), true), true, flags: \JSON_THROW_ON_ERROR);
        $topics = [];
        foreach ($claims['authorization_details'] as $detail) {
            self::assertSame(['subscribe'], $detail['actions']);
            foreach ($detail['topics'] as $topic) {
                $topics[] = $topic['match'];
            }
        }

        return $topics;
    }

    /**
     * @return list<string> the names of the cookies the response sets for the hub
     */
    private function hubCookies(): array
    {
        return array_values(array_filter(
            array_map(static fn ($cookie): string => $cookie->getName(), $this->client->getResponse()->headers->getCookies()),
            static fn (string $name): bool => str_contains($name, 'mercure'),
        ));
    }
}
