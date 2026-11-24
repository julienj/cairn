<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\TechnicalReport;
use PHPUnit\Framework\TestCase;

/**
 * What a site says of a visit is kept in the shape known here and in no
 * other: it comes from a page nobody here controls.
 */
final class TechnicalReportTest extends TestCase
{
    public function testWhatIsNotAReportIsNone(): void
    {
        self::assertNull(TechnicalReport::from(null));
        self::assertNull(TechnicalReport::from('a string'));
        self::assertNull(TechnicalReport::from(['errors' => []]), 'a report names its page');
        self::assertNull(TechnicalReport::from(['page' => ['url' => ['an', 'array']]]));
    }

    public function testOnlyTheKnownShapeIsKept(): void
    {
        $report = TechnicalReport::from([
            'page' => ['url' => 'https://staging.example.com/cart', 'title' => " Cart \x00", 'cookie' => 'secret'],
            'environment' => [
                'userAgent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36',
                'viewport' => ['width' => 1280, 'height' => 800],
                'screen' => ['width' => 'wide', 'height' => 1440],
                'pixelRatio' => 2,
                'plugins' => ['anything'],
            ],
            'errors' => [
                ['kind' => 'console', 'message' => str_repeat('a', 5000), 'source' => 'https://staging.example.com/app.js:42', 'at' => '2026-11-24T10:14:07.123Z'],
                ['kind' => 'made-up', 'message' => 'Boom', 'at' => 'yesterday'],
                ['message' => ['not', 'a', 'text']],
                'not an entry',
            ],
            'requests' => [
                ['method' => 'post', 'url' => 'https://staging.example.com/api/cart', 'status' => 500],
                ['method' => 'DROP TABLE', 'url' => 'https://staging.example.com/api', 'status' => 99999, 'at' => '2026-99-99T99:99:99Z'],
            ],
            'pages' => array_map(static fn (int $i): array => ['url' => 'https://staging.example.com/page/'.$i], range(1, 80)),
            'secrets' => ['token' => 'abc'],
        ]);

        self::assertNotNull($report);
        self::assertSame(['page', 'environment', 'errors', 'requests', 'pages', 'collectedAt'], array_keys($report->toArray()));
        self::assertSame(['url' => 'https://staging.example.com/cart', 'title' => 'Cart'], $report->page());
        self::assertSame(['width' => 1280, 'height' => 800], $report->environment()['viewport']);
        self::assertNull($report->environment()['screen'], 'a size is two numbers');
        self::assertSame(2.0, $report->environment()['pixelRatio']);
        self::assertSame('Chrome 154 · macOS', $report->browser());

        self::assertCount(2, $report->errors());
        self::assertSame(600, mb_strlen($report->errors()[0]['message']));
        self::assertSame('2026-11-24T10:14:07.123Z', $report->errors()[0]['at']);
        self::assertSame(['kind' => 'error', 'message' => 'Boom', 'source' => null, 'at' => null], $report->errors()[1]);

        self::assertSame(['method' => 'POST', 'url' => 'https://staging.example.com/api/cart', 'status' => 500, 'at' => null], $report->requests()[0]);
        self::assertSame(['GET', 0], [$report->requests()[1]['method'], $report->requests()[1]['status']]);
        self::assertNull($report->requests()[1]['at'], 'a moment that does not exist is no moment');

        self::assertCount(30, $report->pages(), 'the last ones seen');
        self::assertSame('https://staging.example.com/page/80', $report->pages()[0]['url'], 'the last one first');
    }

    public function testWhatAnIssueKeptIsReadAgainAsItIs(): void
    {
        $report = TechnicalReport::from(['page' => ['url' => 'https://staging.example.com/'], 'requests' => [['method' => 'GET', 'url' => 'https://staging.example.com/api', 'status' => 0]]]);
        self::assertNotNull($report);

        self::assertSame($report->toArray(), TechnicalReport::from($report->toArray())?->toArray());
        self::assertNull($report->browser());
    }
}
