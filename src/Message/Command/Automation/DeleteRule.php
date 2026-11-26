<?php

declare(strict_types=1);

namespace App\Message\Command\Automation;

/**
 * Deletes an automation rule: it is configuration, the row goes. What
 * the rule did stays in the journal of the issues and in its executions,
 * under its name. The actor, when there is one, needs
 * `automation.manage`. Handled by DeleteRuleHandler.
 */
final readonly class DeleteRule
{
    public function __construct(
        public int $ruleId,
        public ?int $actorId = null,
    ) {
    }
}
