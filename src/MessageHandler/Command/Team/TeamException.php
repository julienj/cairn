<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Team;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a team cannot be carried out as asked. The message is for
 * the logs; the administration shows the translated reason.
 */
final class TeamException extends \DomainException implements TranslatableInterface
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
        return new self(\sprintf('%s %d not found.', $what, $id), 'admin.refused.not_found');
    }

    public static function nameTaken(string $name): self
    {
        return new self(\sprintf('A team is already named "%s".', $name), 'admin.refused.team_name_taken', ['%name%' => $name]);
    }

    public static function externalMember(string $userName, string $teamName): self
    {
        return new self(\sprintf('%s is external: the team "%s" is internal.', $userName, $teamName), 'admin.refused.team_internal_only', ['%name%' => $userName, '%team%' => $teamName]);
    }

    public static function deactivatedMember(string $userName): self
    {
        return new self(\sprintf('%s is deactivated.', $userName), 'admin.refused.deactivated', ['%name%' => $userName]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}
