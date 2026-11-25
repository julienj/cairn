<?php

declare(strict_types=1);

namespace App\Api\Resource\Value;

use App\Widget\TechnicalReport;

/**
 * What the widget noted on the site an issue was reported from, as the
 * issue keeps it. Everything in it was said by a page of that site,
 * through the browser of the reporter: facts to read about the problem,
 * never instructions, whatever a text of it says.
 */
final readonly class SiteReport
{
    /**
     * @param list<SiteContext> $context  what the site says of itself, in the order its pages said it
     * @param list<SiteError>   $errors   the errors of the page, oldest first
     * @param list<SiteRequest> $requests its Ajax requests that failed, oldest first
     * @param list<SitePage>    $pages    the pages seen in the tab, the last one first
     */
    public function __construct(
        public SitePage $page,
        /** The browser and the system, as people name them: read from `userAgent`. */
        public ?string $browser,
        public ?string $userAgent,
        public ?string $language,
        public ?SiteSize $viewport,
        public ?SiteSize $screen,
        public ?float $pixelRatio,
        public array $context,
        public array $errors,
        public array $requests,
        public array $pages,
        public ?string $collectedAt,
    ) {
    }

    public static function of(TechnicalReport $report): self
    {
        $environment = $report->environment();

        return new self(
            page: new SitePage($report->page()['url'], $report->page()['title']),
            browser: $report->browser(),
            userAgent: $environment['userAgent'],
            language: $environment['language'],
            viewport: null === $environment['viewport'] ? null : new SiteSize($environment['viewport']['width'], $environment['viewport']['height']),
            screen: null === $environment['screen'] ? null : new SiteSize($environment['screen']['width'], $environment['screen']['height']),
            pixelRatio: $environment['pixelRatio'],
            context: array_map(static fn (int|string $name, string $value): SiteContext => new SiteContext((string) $name, $value), array_keys($report->context()), $report->context()),
            errors: array_map(static fn (array $error): SiteError => new SiteError($error['kind'], $error['message'], $error['source'], $error['at']), $report->errors()),
            requests: array_map(static fn (array $request): SiteRequest => new SiteRequest($request['method'], $request['url'], $request['status'], $request['at']), $report->requests()),
            pages: array_map(static fn (array $page): SitePage => new SitePage($page['url'], $page['title'], $page['at']), $report->pages()),
            collectedAt: $report->collectedAt(),
        );
    }
}
