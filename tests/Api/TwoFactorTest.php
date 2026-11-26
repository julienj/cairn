<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Security\TwoFactorHelpers;

/**
 * The second factor is the sign-in's: a personal token signs the requests of the API and of the MCP server, for an
 * account that holds one and for one that does not, on an instance that requires it of everybody.
 */
final class TwoFactorTest extends ApiTestCase
{
    use TwoFactorHelpers;

    protected function setUp(): void
    {
        $this->requireTwoFactor('all');
        parent::setUp();
    }

    public function testAnAccountWithASecondFactorUsesItsTokenWithoutACode(): void
    {
        $this->enableTwoFactorOn($this->scene->user(self::DEVELOPER), self::totpSecret());
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $me = $this->api('GET', '/api/me', $secret);

        self::assertResponseIsSuccessful();
        self::assertSame(self::DEVELOPER, $me['email']);
    }

    public function testAnAccountThatHasNotSetItUpYetIsNotSentToThePageThatDoesItByTheApi(): void
    {
        $secret = $this->tokenFor(self::MANAGER)->secret;

        $me = $this->api('GET', '/api/me', $secret);

        self::assertResponseIsSuccessful('a redirect would break every program');
        self::assertSame(self::MANAGER, $me['email']);
    }
}
