<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Template;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A command on a project template cannot be carried out as asked. The
 * message is for the logs; the administration shows the translated
 * reason. What a rule of a template may not say is an
 * Automation\AutomationException, as for the rule of a project.
 */
final class TemplateException extends \DomainException implements TranslatableInterface
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
        return new self(\sprintf('A template is already named "%s".', $name), 'admin.refused.template_name_taken', ['%name%' => $name]);
    }

    public static function unknownRole(string $role): self
    {
        return new self(\sprintf('Role "%s" does not exist in the configuration.', $role), 'admin.refused.unknown_role', ['%role%' => $role]);
    }

    public static function internalOnlyRole(string $roleLabel, string $teamName): self
    {
        return new self(\sprintf('Role "%s" cannot be given to the team "%s", which is external.', $roleLabel, $teamName), 'admin.refused.team_internal_only_role', ['%role%' => $roleLabel, '%team%' => $teamName]);
    }

    public static function unknownWorkflow(string $workflowId): self
    {
        return new self(\sprintf('Workflow "%s" does not exist in the configuration.', $workflowId), 'admin.refused.unknown_workflow', ['%workflow%' => $workflowId]);
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans($this->reason, $this->parameters, null, $locale);
    }
}
