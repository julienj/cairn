<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\User;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a user account cannot be carried out as asked. The message is for the logs; the administration
 * shows the translated reason.
 */
final class UserException extends \DomainException implements TranslatableInterface
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

    public static function notFound(int $id): self
    {
        return new self(\sprintf('User %d not found.', $id), 'admin.refused.not_found');
    }

    public static function emailTaken(string $email): self
    {
        return new self(\sprintf('An account already uses "%s".', $email), 'admin.refused.email_taken', ['%email%' => $email]);
    }

    public static function lastAdministrator(): self
    {
        return new self('The last active administrator must stay one.', 'admin.refused.last_admin');
    }

    /**
     * @param list<string> $where the workspaces and projects of those roles
     */
    public static function holdsInternalRoles(string $userName, array $where): self
    {
        return new self(\sprintf('%s holds internal roles on: %s.', $userName, implode(', ', $where)), 'admin.refused.holds_internal_roles', ['%name%' => $userName, '%where%' => implode(', ', $where)]);
    }

    /**
     * @param list<string> $teams the internal teams the person is part of
     */
    public static function inInternalTeams(string $userName, array $teams): self
    {
        return new self(\sprintf('%s is part of internal teams: %s.', $userName, implode(', ', $teams)), 'admin.refused.in_internal_teams', ['%name%' => $userName, '%teams%' => implode(', ', $teams)]);
    }

    public static function deactivated(string $userName): self
    {
        return new self(\sprintf('%s is deactivated.', $userName), 'admin.refused.deactivated', ['%name%' => $userName]);
    }

    public static function alreadyActivated(string $userName): self
    {
        return new self(\sprintf('%s already has a password.', $userName), 'admin.refused.already_activated', ['%name%' => $userName]);
    }

    public static function signsInWithSso(string $userName): self
    {
        return new self(\sprintf('%s signs in through the identity provider.', $userName), 'admin.refused.signs_in_with_sso', ['%name%' => $userName]);
    }

    public static function ssoNotConfigured(): self
    {
        return new self('The instance has no identity provider.', 'admin.refused.sso_not_configured');
    }

    public static function notMemberOf(string $userName, string $projectName): self
    {
        return new self(\sprintf('%s holds no role on %s.', $userName, $projectName), 'admin.refused.not_member', ['%name%' => $userName, '%project%' => $projectName]);
    }

    public static function externalOnlyFromProject(): self
    {
        return new self('An account created from a project is external.', 'admin.refused.external_only_from_project');
    }

    public static function wrongPassword(): self
    {
        return new self('The current password is not the one typed.', 'account.refused.wrong_password');
    }

    public static function hasHistory(string $userName): self
    {
        return new self(\sprintf('%s wrote or holds issues: the account is deactivated, not deleted.', $userName), 'admin.refused.has_history', ['%name%' => $userName]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}
