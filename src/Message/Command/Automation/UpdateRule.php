<?php

declare(strict_types=1);

namespace App\Message\Command\Automation;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Changes an automation rule, PATCH style: a property left null keeps
 * its value. `active` is a property, not an action. The tracker and the
 * definition are checked together, a step may name a place of the
 * tracker: `trackerId` NO_TRACKER makes the rule hold on every tracker.
 * The actor, when there is one, needs `automation.manage`. Handled by
 * UpdateRuleHandler.
 */
final readonly class UpdateRule
{
    public const int NO_TRACKER = 0;

    /**
     * @param array<string, mixed>|null $definition
     */
    public function __construct(
        public int $ruleId,
        #[Assert\NotBlank(normalizer: 'trim', allowNull: true)]
        #[Assert\Length(max: 120)]
        public ?string $name = null,
        public ?bool $active = null,
        public ?int $trackerId = null,
        public ?array $definition = null,
        public ?int $actorId = null,
    ) {
    }
}
