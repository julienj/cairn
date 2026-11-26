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
                'zoom' => 1.5,
                'preferences' => ['colorScheme' => 'dark', 'reducedMotion' => true, 'contrast' => 'maximal', 'forcedColors' => 'yes', 'pointer' => 'coarse', 'battery' => 'low'],
                'plugins' => ['anything'],
            ],
            'context' => ['version' => '2026.11.3', 'user' => ' claire@example.com ', 'cart' => ['an', 'array'], 'empty' => '', str_repeat('n', 100) => 'cut name'],
            'errors' => [
                ['kind' => 'console', 'message' => str_repeat('a', 5000), 'source' => 'https://staging.example.com/app.js:42', 'at' => '2026-11-24T10:14:07.123Z'],
                ['kind' => 'made-up', 'message' => 'Boom', 'at' => 'yesterday'],
                ['message' => ['not', 'a', 'text']],
                'not an entry',
            ],
            'network' => [
                ['kind' => 'ajax', 'method' => 'post', 'url' => 'https://staging.example.com/api/cart', 'status' => 500, 'at' => '2026-11-24T10:14:06.000Z'],
                ['kind' => 'ajax', 'method' => 'DROP TABLE', 'url' => 'https://staging.example.com/api', 'status' => 99999, 'at' => '2026-99-99T99:99:99Z'],
                ['kind' => 'font', 'method' => 'GET', 'url' => 'https://staging.example.com/fonts/brume.woff2', 'status' => 404, 'at' => '2026-11-24T10:14:05.000Z'],
                ['kind' => 'video game', 'url' => 'https://cdn.example.com/hero', 'status' => 'Not Found', 'at' => '2026-11-24T10:14:08.000Z'],
                ['kind' => 'image'],
            ],
            'pages' => array_map(static fn (int $i): array => ['url' => 'https://staging.example.com/page/'.$i], range(1, 80)),
            'secrets' => ['token' => 'abc'],
        ]);

        self::assertNotNull($report);
        self::assertSame(['page', 'environment', 'context', 'errors', 'network', 'pages', 'actions', 'collectedAt'], array_keys($report->toArray()));
        self::assertSame(['url' => 'https://staging.example.com/cart', 'title' => 'Cart'], $report->page());
        self::assertSame(['width' => 1280, 'height' => 800], $report->environment()['viewport']);
        self::assertNull($report->environment()['screen'], 'a size is two numbers');
        self::assertSame(2.0, $report->environment()['pixelRatio']);
        self::assertSame(1.5, $report->environment()['zoom']);
        self::assertSame(['colorScheme' => 'dark', 'reducedMotion' => true, 'contrast' => null, 'forcedColors' => null, 'pointer' => 'coarse'], $report->environment()['preferences'], 'a preference is one of the values of its media query');
        self::assertSame('Chrome 154 · macOS', $report->browser());
        self::assertSame(['version' => '2026.11.3', 'user' => 'claire@example.com', str_repeat('n', 60) => 'cut name'], $report->context(), 'names and texts only');

        self::assertCount(2, $report->errors());
        self::assertSame(600, mb_strlen($report->errors()[0]['message']));
        self::assertSame('2026-11-24T10:14:07.123Z', $report->errors()[0]['at']);
        self::assertSame(['kind' => 'error', 'message' => 'Boom', 'source' => null, 'at' => null], $report->errors()[1]);

        self::assertSame([
            ['kind' => 'font', 'method' => null, 'url' => 'https://staging.example.com/fonts/brume.woff2', 'status' => 404, 'at' => '2026-11-24T10:14:05.000Z'],
            ['kind' => 'ajax', 'method' => 'POST', 'url' => 'https://staging.example.com/api/cart', 'status' => 500, 'at' => '2026-11-24T10:14:06.000Z'],
            ['kind' => 'other', 'method' => null, 'url' => 'https://cdn.example.com/hero', 'status' => null, 'at' => '2026-11-24T10:14:08.000Z'],
            ['kind' => 'ajax', 'method' => 'GET', 'url' => 'https://staging.example.com/api', 'status' => 0, 'at' => null],
        ], $report->network(), 'in the order it failed, a method for a request only, a file without a known status has none; a moment that does not exist is no moment, and comes last');

        self::assertCount(30, $report->pages(), 'the last ones seen');
        self::assertSame('https://staging.example.com/page/80', $report->pages()[0]['url'], 'the last one first');
    }

    public function testWhatAnIssueKeptIsReadAgainAsItIs(): void
    {
        $report = TechnicalReport::from(['page' => ['url' => 'https://staging.example.com/'], 'network' => [['kind' => 'ajax', 'method' => 'GET', 'url' => 'https://staging.example.com/api', 'status' => 0]]]);
        self::assertNotNull($report);

        self::assertSame($report->toArray(), TechnicalReport::from($report->toArray())?->toArray());
        self::assertNull($report->browser());
        self::assertNull($report->environment()['preferences']);
    }

    public function testKeepsWhatWasDoneAndNeverMoreThanItsShape(): void
    {
        $report = TechnicalReport::from([
            'page' => ['url' => 'https://staging.example.com/cart'],
            'actions' => [
                ['kind' => 'submit', 'role' => 'form', 'name' => 'Checkout', 'selector' => '#checkout', 'page' => 'https://staging.example.com/cart', 'count' => 1, 'at' => '2026-11-24T10:14:09.000Z', 'value' => 'what was typed'],
                ['kind' => 'click', 'role' => 'BUTTON', 'name' => str_repeat('n', 500), 'selector' => str_repeat('s', 500), 'count' => 4000, 'at' => '2026-11-24T10:14:05.000Z'],
                ['kind' => 'keypress', 'name' => 'a', 'at' => '2026-11-24T10:14:06.000Z'],
                ['kind' => 'click', 'name' => 'No moment', 'at' => 'just now'],
                ['kind' => 'change', 'role' => '<script>', 'name' => 'Email', 'count' => 'many', 'at' => '2026-11-24T10:14:07.000Z'],
            ],
        ]);

        self::assertNotNull($report);
        self::assertSame(['click', 'change', 'submit'], array_column($report->actions(), 'kind'), 'the kinds known here, each with its moment, oldest first');
        self::assertSame(['kind', 'role', 'name', 'selector', 'page', 'count', 'at'], array_keys($report->actions()[2]), 'nothing a site adds is kept');
        self::assertSame(['button', 60, 200, null, 1], [$report->actions()[0]['role'], mb_strlen((string) $report->actions()[0]['name']), mb_strlen((string) $report->actions()[0]['selector']), $report->actions()[0]['page'], $report->actions()[0]['count']]);
        self::assertSame([null, 'Email', 1], [$report->actions()[1]['role'], $report->actions()[1]['name'], $report->actions()[1]['count']]);
    }

    public function testReadsWhatFollowedAnActionInItsOrder(): void
    {
        $report = TechnicalReport::from([
            'page' => ['url' => 'https://staging.example.com/cart'],
            'errors' => [
                ['kind' => 'console', 'message' => 'Before anything was done', 'at' => '2026-11-24T10:14:00.000Z'],
                ['kind' => 'console', 'message' => 'Price not found', 'at' => '2026-11-24T10:14:06.500Z'],
                ['kind' => 'error', 'message' => 'No moment'],
            ],
            'network' => [['kind' => 'ajax', 'method' => 'POST', 'url' => 'https://staging.example.com/api/cart', 'status' => 500, 'at' => '2026-11-24T10:14:06.000Z']],
            'pages' => [
                ['url' => 'https://staging.example.com/', 'at' => '2026-11-24T10:13:00.000Z'],
                ['url' => 'https://staging.example.com/cart', 'at' => '2026-11-24T10:14:08.000Z'],
            ],
            'actions' => [
                ['kind' => 'click', 'name' => 'Cart', 'at' => '2026-11-24T10:14:07.000Z'],
                ['kind' => 'click', 'name' => 'Add to cart', 'at' => '2026-11-24T10:14:06.000Z'],
            ],
        ]);

        self::assertNotNull($report);
        self::assertSame([
            ['page', 'https://staging.example.com/'],
            ['error', 'Before anything was done'],
            ['action', 'Add to cart'],
            ['request', 'https://staging.example.com/api/cart'],
            ['error', 'Price not found'],
            ['action', 'Cart'],
            ['page', 'https://staging.example.com/cart'],
            ['error', 'No moment'],
        ], array_map(static fn (array $event): array => [$event['type'], $event['entry']['name'] ?? $event['entry']['message'] ?? $event['entry']['url']], $report->timeline()), 'an action before what it caused, what has no moment last');
    }
}
