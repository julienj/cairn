<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * What the script of the widget noted on the site a problem is reported
 * from: the page, the browser, the errors of the page, its Ajax requests
 * that failed and the pages seen in the tab. It comes from a page nobody
 * here controls, through the browser of the reporter: only the shape
 * known here is kept, every text cut and every list bounded, and it is
 * shown as what the site said, never trusted for anything.
 */
final readonly class TechnicalReport
{
    private const int MAX_ENTRIES = 30;
    private const int MAX_TEXT = 600;
    private const int MAX_ADDRESS = 400;

    private const array ERROR_KINDS = ['console', 'error', 'rejection'];

    /**
     * @param array{
     *     page: array{url: string, title: ?string},
     *     environment: array{userAgent: ?string, language: ?string, viewport: ?array{width: int, height: int}, screen: ?array{width: int, height: int}, pixelRatio: ?float, version: ?string},
     *     errors: list<array{kind: string, message: string, source: ?string, at: ?string}>,
     *     requests: list<array{method: string, url: string, status: int, at: ?string}>,
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
        $pixelRatio = $environment['pixelRatio'] ?? null;

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
        $requests = [];
        foreach (self::entries($raw['requests'] ?? null) as $entry) {
            if (null !== $address = self::text($entry['url'] ?? null, self::MAX_ADDRESS)) {
                $method = strtoupper((string) self::text($entry['method'] ?? null, 10));
                $requests[] = [
                    'method' => 1 === preg_match('/^[A-Z]+$/', $method) ? $method : 'GET',
                    'url' => $address,
                    'status' => self::number($entry['status'] ?? null, 0, 599) ?? 0,
                    'at' => self::time($entry['at'] ?? null),
                ];
            }
        }
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
                'pixelRatio' => (\is_int($pixelRatio) || \is_float($pixelRatio)) && $pixelRatio > 0 && $pixelRatio <= 10 ? round((float) $pixelRatio, 2) : null,
                'version' => self::text($environment['version'] ?? null, 60),
            ],
            'errors' => $errors,
            'requests' => $requests,
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
     * @return array{userAgent: ?string, language: ?string, viewport: ?array{width: int, height: int}, screen: ?array{width: int, height: int}, pixelRatio: ?float, version: ?string}
     */
    public function environment(): array
    {
        return $this->data['environment'];
    }

    /**
     * @return list<array{kind: string, message: string, source: ?string, at: ?string}>
     */
    public function errors(): array
    {
        return $this->data['errors'];
    }

    /**
     * @return list<array{method: string, url: string, status: int, at: ?string}>
     */
    public function requests(): array
    {
        return $this->data['requests'];
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
    private static function entries(mixed $list): array
    {
        if (!\is_array($list)) {
            return [];
        }

        return \array_slice(array_values(array_filter($list, \is_array(...))), -self::MAX_ENTRIES);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));

        return '' === $value ? null : mb_substr($value, 0, $max);
    }

    private static function number(mixed $value, int $min, int $max): ?int
    {
        return \is_int($value) && $value >= $min && $value <= $max ? $value : null;
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
