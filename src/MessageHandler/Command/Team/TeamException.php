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

    public static function holdsIssues(string $name, int $count): self
    {
        return new self(\sprintf('%d issue(s) are assigned to the team "%s".', $count, $name), 'admin.refused.team_holds_issues', ['%name%' => $name, '%count%' => (string) $count]);
    }

    public static function unknownRole(string $role): self
    {
        return new self(\sprintf('Role "%s" does not exist in the configuration.', $role), 'admin.refused.unknown_role', ['%role%' => $role]);
    }

    public static function internalOnlyRole(string $roleLabel, string $teamName): self
    {
        return new self(\sprintf('Role "%s" cannot be granted to the team "%s", which is external.', $roleLabel, $teamName), 'admin.refused.team_internal_only_role', ['%role%' => $roleLabel, '%team%' => $teamName]);
    }

    public static function notWiderThanInherited(string $roleLabel, string $inheritedLabel): self
    {
        return new self(\sprintf('Role "%s" does not widen the role "%s" the team holds on the workspace.', $roleLabel, $inheritedLabel), 'admin.refused.team_not_wider', ['%role%' => $roleLabel, '%inherited%' => $inheritedLabel]);
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
