<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Enum\ApiTokenScope;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Around an issue: its links, the files sent ahead, following it, its
 * history.
 */
final class IssueExtrasApiTest extends ApiTestCase
{
    public function testALinkReadsFromEachSide(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;

        $link = $this->api('POST', '/api/issues/MD-SPT-1/links', $secret, ['type' => 'blocks', 'issue' => 'md-spt-2']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('MD-SPT-1', $link['issue']);
        self::assertSame(['blocks', 'Blocks', false], [$link['type'], $link['label'], $link['inverse']]);
        self::assertSame('MD-SPT-2', $link['other']['key']);

        $fromTheOther = $this->api('GET', '/api/issues/MD-SPT-2/links', $secret);
        self::assertCount(1, $fromTheOther['member']);
        self::assertSame($link['id'], $fromTheOther['member'][0]['id']);
        self::assertSame(['blocks', 'Blocked by', true], [$fromTheOther['member'][0]['type'], $fromTheOther['member'][0]['label'], $fromTheOther['member'][0]['inverse']]);
        self::assertSame('MD-SPT-1', $fromTheOther['member'][0]['other']['key']);

        $inverse = $this->api('POST', '/api/issues/MD-SPT-1/links', $secret, ['type' => 'blocks', 'issue' => 'MD-SPT-3', 'inverse' => true]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('Blocked by', $inverse['label']);

        $this->api('POST', '/api/issues/MD-SPT-1/links', $secret, ['type' => 'blocks', 'issue' => 'MD-SPT-2']);
        self::assertResponseStatusCodeSame(422, 'The same link twice.');
        $this->api('POST', '/api/issues/MD-SPT-1/links', $secret, ['type' => 'blocks', 'issue' => 'MD-SPT-999']);
        self::assertResponseStatusCodeSame(422);

        $this->api('DELETE', '/api/links/'.$link['id'], $secret);
        self::assertResponseStatusCodeSame(204);
        self::assertCount(0, $this->api('GET', '/api/issues/MD-SPT-2/links', $secret)['member']);
        $this->api('DELETE', '/api/links/'.$link['id'], $secret);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAFileSentAheadGoesWithTheComment(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;
        $path = tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents((string) $path, 'the log of the failure');

        $this->client->request('POST', '/api/projects/MD-SPT/attachments', files: ['file' => new UploadedFile((string) $path, 'failure.log', 'text/plain', null, true)], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseStatusCodeSame(201);
        $file = (array) json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('failure.log', $file['name']);
        self::assertSame(22, $file['size']);

        $comment = $this->api('POST', '/api/issues/MD-SPT-1/comments', $secret, ['body' => 'See the log.', 'attachments' => [$file['uuid']]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([$file['uuid']], array_column($comment['attachments'], 'uuid'));
        self::assertSame($file['url'], $comment['attachments'][0]['url']);

        $this->client->request('POST', '/api/projects/MD-SPT/attachments', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'CONTENT_TYPE' => 'multipart/form-data']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testAFileIsDownloadedWithAToken(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;
        $path = tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents((string) $path, 'the log of the failure');
        $this->client->request('POST', '/api/projects/MD-SPT/attachments', files: ['file' => new UploadedFile((string) $path, 'failure.log', 'text/plain', null, true)], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret, 'HTTP_ACCEPT' => 'application/ld+json']);
        $file = (array) json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('http://localhost/api/attachments/'.$file['uuid'].'/content', $file['contentUrl']);
        $reader = $this->tokenFor(self::MANAGER, ApiTokenScope::Read)->secret;

        // While it waits, the file is its uploader's.
        $this->client->request('GET', $file['contentUrl'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$reader]);
        self::assertResponseStatusCodeSame(404);

        $this->api('POST', '/api/issues/MD-SPT-1/comments', $secret, ['body' => 'See the log.', 'attachments' => [$file['uuid']]]);

        $this->client->request('GET', $file['contentUrl'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$reader]);
        self::assertResponseIsSuccessful();
        self::assertSame('the log of the failure', $this->client->getInternalResponse()->getContent());
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertStringContainsString('attachment; filename=failure.log', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        self::assertSame('failure.log', $this->api('GET', '/api/attachments/'.$file['uuid'], $reader)['name']);

        $this->client->request('GET', $file['contentUrl']);
        self::assertResponseStatusCodeSame(401);
        $this->api('GET', '/api/attachments/not-a-uuid/content', $reader);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnIssueIsFollowedAndLeft(): void
    {
        // Marc reads the shop as any internal user, and holds no role on it: following is for who works on the project.
        $outsider = $this->tokenFor(self::OUTSIDER)->secret;
        $this->api('PUT', '/api/issues/MD-SPT-1/subscription', $outsider);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->api('GET', '/api/issues/MD-SPT-1/subscription', $outsider)['following']);

        $secret = $this->tokenFor(self::DEVELOPER)->secret;
        self::assertFalse($this->api('GET', '/api/issues/MD-SPT-1/subscription', $secret)['following']);

        $followed = $this->api('PUT', '/api/issues/MD-SPT-1/subscription', $secret);
        self::assertResponseIsSuccessful();
        self::assertTrue($followed['following']);
        self::assertArrayHasKey('since', $followed);
        self::assertTrue($this->api('PUT', '/api/issues/MD-SPT-1/subscription', $secret)['following'], 'Following twice changes nothing.');

        $this->api('DELETE', '/api/issues/MD-SPT-1/subscription', $secret);
        self::assertResponseStatusCodeSame(204);
        self::assertFalse($this->api('GET', '/api/issues/MD-SPT-1/subscription', $secret)['following']);

        $this->api('PUT', '/api/issues/MD-SPT-999/subscription', $secret);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheHistorySaysWhatHappened(): void
    {
        $secret = $this->tokenFor(self::DEVELOPER)->secret;
        $this->api('POST', '/api/issues/MD-SPT-1/transitions', $secret, ['transition' => 'qualify', 'comment' => 'Reproduced.']);

        $history = $this->api('GET', '/api/issues/MD-SPT-1/history', $secret);
        self::assertResponseIsSuccessful();
        self::assertSame(['created', 'transitioned'], array_column($history['member'], 'type'));
        $last = $history['member'][1];
        self::assertSame('Nadia Benali', $last['author']['name']);
        self::assertStringContainsString('Qualified', $last['label']);
        self::assertFalse($last['private']);

        $comments = $this->api('GET', '/api/issues/MD-SPT-1/comments', $secret);
        self::assertSame([$last['comment']], array_column($comments['member'], 'id'));
    }
}
