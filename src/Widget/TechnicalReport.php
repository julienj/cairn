<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * What the script of the widget noted on the site a problem is reported
 * from: the page, the browser, what the site says of itself (its
 * context: names and texts its pages chose), the errors of the page,
 * what failed on its network (its Ajax requests and its files) and the
 * pages seen in the tab. It comes from a page nobody here controls,
 * through the browser of the reporter: only the shape known here is kept, every text cut and every list bounded, and it is
 * shown as what the site said, never trusted for anything.
 */
final readonly class TechnicalReport
{
    private const int MAX_ENTRIES = 30;
    /** Ajax requests and files together: as many of each as of the other lists. */
    private const int MAX_NETWORK = 60;
    private const int MAX_TEXT = 600;
    private const int MAX_ADDRESS = 400;
    private const int MAX_NAME = 60;

    private const array ERROR_KINDS = ['console', 'error', 'rejection'];
    /** An Ajax request of the page, or one of its files that did not load. */
    private const array NETWORK_KINDS = ['ajax', 'image', 'script', 'stylesheet', 'font', 'media', 'other'];

    /**
     * @param array{
     *     page: array{url: string, title: ?string},
     *     environment: array{userAgent: ?string, language: ?string, viewport: ?array{width: int, height: int}, screen: ?array{width: int, height: int}, pixelRatio: ?float, zoom: ?float, preferences: ?array{colorScheme: ?string, reducedMotion: ?bool, contrast: ?string, forcedColors: ?bool, pointer: ?string}},
     *     context: array<string, string>,
     *     errors: list<array{kind: string, message: string, source: ?string, at: ?string}>,
     *     network: list<array{kind: string, method: ?string, url: string, status: ?int, at: ?string}>,
     *     pages: list<array{url: string, title: ?string, at: ?string}>,
     *     collectedAt: ?string,
     * } $data
     */
    private function __construct(
        private array $data,
    ) {
    }

    /**
     * The report in what was received, null when it does not even name a page.
     */
    public static function from(mixed $raw): ?self
    {
        if (!\is_array($raw) || !\is_array($raw['page'] ?? null) || null === $url = self::text($raw['page']['url'] ?? null, self::MAX_ADDRESS)) {
            return null;
        }
        $environment = \is_array($raw['environment'] ?? null) ? $raw['environment'] : [];

        $errors = [];
        foreach (self::entries($raw['errors'] ?? null) as $entry) {
            if (null !== $message = self::text($entry['message'] ?? null, self::MAX_TEXT)) {
                $kind = $entry['kind'] ?? null;
                $errors[] = [
                    'kind' => \in_array($kind, self::ERROR_KINDS, true) ? $kind : 'error',
                    'message' => $message,
                    'source' => self::text($entry['source'] ?? null, self::MAX_ADDRESS),
                    'at' => self::time($entry['at'] ?? null),
                ];
            }
        }
        $network = [];
        foreach (self::entries($raw['network'] ?? null, self::MAX_NETWORK) as $entry) {
            if (null !== $address = self::text($entry['url'] ?? null, self::MAX_ADDRESS)) {
                $kind = \in_array($entry['kind'] ?? null, self::NETWORK_KINDS, true) ? $entry['kind'] : 'other';
                $method = strtoupper((string) self::text($entry['method'] ?? null, 10));
                $network[] = 'ajax' === $kind ? [
                    'kind' => $kind,
                    'method' => 1 === preg_match('/^[A-Z]+$/', $method) ? $method : 'GET',
                    'url' => $address,
                    // 0: no answer at all
                    'status' => self::number($entry['status'] ?? null, 0, 599) ?? 0,
                    'at' => self::time($entry['at'] ?? null),
                ] : [
                    'kind' => $kind,
                    'method' => null,
                    'url' => $address,
                    // null: the browser did not say
                    'status' => self::number($entry['status'] ?? null, 100, 599),
                    'at' => self::time($entry['at'] ?? null),
                ];
            }
        }
        // in the order it failed, whatever order the page sent it in; what has no moment last
        usort($network, static fn (array $one, array $other): int => [null === $one['at'], $one['at']] <=> [null === $other['at'], $other['at']]);
        $pages = [];
        foreach (self::entries($raw['pages'] ?? null) as $entry) {
            if (null !== $address = self::text($entry['url'] ?? null, self::MAX_ADDRESS)) {
                $pages[] = ['url' => $address, 'title' => self::text($entry['title'] ?? null, self::MAX_TEXT), 'at' => self::time($entry['at'] ?? null)];
            }
        }

        return new self([
            'page' => ['url' => $url, 'title' => self::text($raw['page']['title'] ?? null, self::MAX_TEXT)],
            'environment' => [
                'userAgent' => self::text($environment['userAgent'] ?? null, self::MAX_TEXT),
                'language' => self::text($environment['language'] ?? null, 20),
                'viewport' => self::size($environment['viewport'] ?? null),
                'screen' => self::size($environment['screen'] ?? null),
                'pixelRatio' => self::ratio($environment['pixelRatio'] ?? null),
                'zoom' => self::ratio($environment['zoom'] ?? null),
                'preferences' => self::preferences($environment['preferences'] ?? null),
            ],
            'context' => self::pairs($raw['context'] ?? null),
            'errors' => $errors,
            'network' => $network,
            'pages' => $pages,
            'collectedAt' => self::time($raw['collectedAt'] ?? null),
        ]);
    }

    /**
     * What the issue keeps, and what from() reads again.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array{url: string, title: ?string}
     */
    public function page(): array
    {
        return $this->data['page'];
    }

    /**
     * @return array{userAgent: ?string, language: ?string, viewport: ?array{width: int, height: int}, screen: ?array{width: int, height: int}, pixelRatio: ?float, zoom: ?float, preferences: ?array{colorScheme: ?string, reducedMotion: ?bool, contrast: ?string, forcedColors: ?bool, pointer: ?string}}
     */
    public function environment(): array
    {
        return $this->data['environment'];
    }

    /**
     * What the site says of itself, name => text, in the order its pages said it.
     *
     * @return array<string, string>
     */
    public function context(): array
    {
        return $this->data['context'];
    }

    /**
     * @return list<array{kind: string, message: string, source: ?string, at: ?string}>
     */
    public function errors(): array
    {
        return $this->data['errors'];
    }

    /**
     * What failed on the network, Ajax requests and files together, in the
     * order it failed: its kind (`ajax`, or the kind of the file), the
     * method of a request, its address, the status of the answer (0 for a
     * request that got none, null for a file whose status the browser did
     * not give) and its moment.
     *
     * @return list<array{kind: string, method: ?string, url: string, status: ?int, at: ?string}>
     */
    public function network(): array
    {
        return $this->data['network'];
    }

    /**
     * The pages seen, the last one first.
     *
     * @return list<array{url: string, title: ?string, at: ?string}>
     */
    public function pages(): array
    {
        return array_reverse($this->data['pages']);
    }

    /**
     * When the site handed its notes over, as JavaScript writes a moment.
     */
    public function collectedAt(): ?string
    {
        return $this->data['collectedAt'];
    }

    /**
     * The browser and the system as people name them ("Firefox 145 ·
     * macOS"), read from what the browser says of itself: a help to the
     * reader, the full text stays beside it.
     */
    public function browser(): ?string
    {
        $agent = $this->data['environment']['userAgent'];
        if (null === $agent) {
            return null;
        }
        $browser = null;
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Version' => 'Safari'] as $token => $name) {
            if (1 === preg_match('~'.$token.'/(\d+)~', $agent, $matches) && ('Version' !== $token || str_contains($agent, 'Safari/'))) {
                $browser = $name.' '.$matches[1];
                break;
            }
        }
        $system = match (true) {
            str_contains($agent, 'iPhone'), str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return null === $browser ? null : $browser.(null === $system ? '' : ' · '.$system);
    }

    /**
     * @return list<array<mixed>>
     */
    private static function entries(mixed $list, int $max = self::MAX_ENTRIES): array
    {
        if (!\is_array($list)) {
            return [];
        }

        return \array_slice(array_values(array_filter($list, \is_array(...))), -$max);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));

        return '' === $value ? null : mb_substr($value, 0, $max);
    }

    /**
     * @return array<string, string>
     */
    private static function pairs(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $context = [];
        foreach ($value as $name => $text) {
            $name = self::text((string) $name, self::MAX_NAME);
            $text = self::text($text, self::MAX_TEXT);
            if (null !== $name && null !== $text) {
                $context[$name] = $text;
            }
            if (self::MAX_ENTRIES === \count($context)) {
                break;
            }
        }

        return $context;
    }

    private static function number(mixed $value, int $min, int $max): ?int
    {
        return \is_int($value) && $value >= $min && $value <= $max ? $value : null;
    }

    /**
     * A ratio of pixels or a zoom: a number above 0 and up to 10.
     */
    private static function ratio(mixed $value): ?float
    {
        return (\is_int($value) || \is_float($value)) && $value > 0 && $value <= 10 ? round((float) $value, 2) : null;
    }

    /**
     * What the reporter asked of their system, each preference one of the
     * values a media query gives, or nothing.
     *
     * @return array{colorScheme: ?string, reducedMotion: ?bool, contrast: ?string, forcedColors: ?bool, pointer: ?string}|null
     */
    private static function preferences(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $one = static fn (string $name, array $values): ?string => \in_array($value[$name] ?? null, $values, true) ? $value[$name] : null;
        $flag = static fn (string $name): ?bool => \is_bool($value[$name] ?? null) ? $value[$name] : null;

        return [
            'colorScheme' => $one('colorScheme', ['light', 'dark']),
            'reducedMotion' => $flag('reducedMotion'),
            'contrast' => $one('contrast', ['more', 'less']),
            'forcedColors' => $flag('forcedColors'),
            'pointer' => $one('pointer', ['fine', 'coarse', 'none']),
        ];
    }

    /**
     * @return array{width: int, height: int}|null
     */
    private static function size(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $width = self::number($value['width'] ?? null, 1, 100000);
        $height = self::number($value['height'] ?? null, 1, 100000);

        return null === $width || null === $height ? null : ['width' => $width, 'height' => $height];
    }

    /**
     * A moment as JavaScript writes it (`2026-11-24T10:14:07.123Z`), or
     * nothing: one that exists, since a page formats it as a date.
     */
    private static function time(mixed $value): ?string
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(\.\d{1,3})?Z$/', $value, $parts)) {
            return null;
        }
        [, $year, $month, $day, $hour, $minute, $second] = array_map(intval(...), $parts);

        return checkdate($month, $day, $year) && $hour < 24 && $minute < 60 && $second < 60 ? $value : null;
    }
}
