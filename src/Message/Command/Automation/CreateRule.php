<?php

declare(strict_types=1);

namespace App\Message\Command\Automation;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Writes an automation rule on a project: its name, the tracker it is
 * limited to if any, and what it says, in the shape of
 * Automation\RuleDefinition::toArray(). The actor, when there is one,
 * needs `automation.manage`. Handled by CreateRuleHandler, which checks
 * the rule against the project (Automation\RuleChecker) and returns the
 * AutomationRule.
 */
final readonly class CreateRule
{
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public int $projectId,
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 120)]
        public string $name,
        public ?int $trackerId,
        public array $definition,
        public bool $active = true,
        public ?int $actorId = null,
    ) {
    }
}
