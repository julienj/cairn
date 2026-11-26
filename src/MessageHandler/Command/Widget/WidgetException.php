<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Widget;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on the widget of a project cannot be carried out as asked.
 * The message is for the logs; the page shows the translated reason.
 */
final class WidgetException extends \DomainException implements TranslatableInterface
{
    /**
     * @param array<string, string> $parameters
     */
    private function __construct(
        string $message,
        private readonly string $reason,
        private readonly array $parameters = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what, int $id): self
    {
        return new self(\sprintf('%s %d not found.', $what, $id), 'project.settings.refused.not_found');
    }

    public static function notAnOrigin(string $address): self
    {
        return new self(\sprintf('"%s" is not the address of a site.', $address), 'widget.refused.not_an_origin');
    }

    public static function siteTaken(string $origin): self
    {
        return new self(\sprintf('The project already has the site %s.', $origin), 'widget.refused.site_taken', ['%origin%' => $origin]);
    }

    public static function unknownSite(string $origin): self
    {
        return new self(\sprintf('%s is not a site of the project.', $origin), 'widget.refused.unknown_site', ['%origin%' => $origin]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}
