<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class ApiTokenTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testATokenIsCreatedShownOnceThenRevoked(): void
    {
        // The expiry is counted by the page and the creation dated by the entity: one moment for both.
        self::mockTime();
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        $crawler = $this->client->request('GET', '/account/tokens');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'API tokens');
        self::assertSelectorExists('a[href="/account/tokens"]', 'The user menu leads to the page.');

        $this->client->submit($crawler->selectButton('Create the token')->form(), ['api_token[name]' => 'Deploy script', 'api_token[scope]' => 'write', 'api_token[lifetime]' => '30d']);
        self::assertResponseRedirects('/account/tokens');
        $crawler = $this->client->followRedirect();

        $secret = $crawler->filter('[data-test="api-token-secret"]')->text();
        self::assertMatchesRegularExpression(ApiToken::PATTERN, $secret);
        self::assertSelectorTextContains('ul li', 'Deploy script');
        $token = self::getContainer()->get(ApiTokenRepository::class)->findOneBySecret($secret);
        self::assertNotNull($token);
        self::assertNotNull($token->getExpiresAt());
        self::assertSame(30, $token->getCreatedAt()->diff($token->getExpiresAt())->days);
        self::assertSelectorExists('a[href="/api/docs"]', 'The page leads to the documentation.');

        // The token opens the API.
        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        self::assertResponseIsSuccessful();

        // The secret is not shown a second time.
        $crawler = $this->client->request('GET', '/account/tokens');
        self::assertSelectorNotExists('[data-test="api-token-secret"]');
        self::assertStringNotContainsString($secret, (string) $this->client->getResponse()->getContent());

        $this->client->submit($crawler->selectButton('Revoke')->form());
        self::assertResponseRedirects('/account/tokens');
        $this->client->followRedirect();
        self::assertSelectorTextContains('[data-test="api-tokens-past"]', 'Deploy script');
        self::assertSelectorTextContains('[data-test="api-tokens-past"]', 'Revoked on');

        $this->client->request('GET', '/api/me', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAnInvalidTokenIsNotCreated(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        $crawler = $this->client->request('GET', '/account/tokens');
        $this->client->submit($crawler->selectButton('Create the token')->form(), ['api_token[name]' => '']);

        self::assertResponseStatusCodeSame(422);
    }

    public function testThePageIsForInternalUsers(): void
    {
        $this->client->loginUser($this->scene->user('claire.martin@maison-dupont.test'));

        $this->client->request('GET', '/projects');
        self::assertSelectorNotExists('a[href="/account/tokens"]');

        $this->client->request('GET', '/account/tokens');
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheMcpPageGivesTheCommandToInternalUsers(): void
    {
        $this->client->loginUser($this->scene->user('nadia.benali@cairn.test'));

        $crawler = $this->client->request('GET', '/account/mcp');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/account/mcp"]');
        self::assertStringContainsString('claude mcp add --scope user --transport http cairn http://localhost/mcp --header "Authorization: Bearer', $crawler->filter('[data-test="mcp-command"]')->text());
        self::assertCount(1, $crawler->filter('[data-controller="clipboard"] button[data-action="clipboard#copy"]'));
        self::assertSame('Copy command to clipboard', trim($crawler->filter('[role="tooltip"] [data-clipboard-target="idle"]')->text()));
        self::assertSame('Copied!', trim($crawler->filter('[role="tooltip"] [data-clipboard-target="done"][hidden]')->text()));

        $this->client->loginUser($this->scene->user('claire.martin@maison-dupont.test'));
        $this->client->request('GET', '/account');
        self::assertSelectorNotExists('a[href="/account/mcp"]');
        $this->client->request('GET', '/account/mcp');
        self::assertResponseStatusCodeSame(403);
    }
}
