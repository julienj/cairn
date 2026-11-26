<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\ConditionType;
use App\Automation\Definition\RuleDefinition;
use App\Automation\Definition\RuleStep;
use App\Entity\Issue;
use App\Entity\User;
use App\Repository\CodeReferenceRepository;

/**
 * Whether the conditions of a rule hold for an issue, as it stands when
 * the rule is triggered: all of them must. The one place that reads what
 * each ConditionType means.
 */
final class RuleConditions
{
    public function __construct(
        private readonly CodeReferenceRepository $codeReferences,
    ) {
    }

    /**
     * @param User|null $actor who did what triggered the rule; nobody when time did, or another rule
     */
    public function hold(RuleDefinition $definition, Issue $issue, ?User $actor): bool
    {
        foreach ($definition->conditions as $condition) {
            if (!$this->holds($condition, $issue, $actor)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param RuleStep<ConditionType> $condition
     */
    private function holds(RuleStep $condition, Issue $issue, ?User $actor): bool
    {
        $expected = $condition->text('is');
        // A team counts as its kind does; nobody is neither a person nor a team.
        $internal = $issue->getAssignee()?->isInternal() ?? $issue->getAssigneeTeam()?->isInternal();

        return match ($condition->type) {
            ConditionType::Actor => null !== $actor && $actor->isInternal() === (ConditionType::INTERNAL === $expected),
            ConditionType::Assignee => match ($expected) {
                ConditionType::NOBODY => null === $internal,
                ConditionType::INTERNAL => true === $internal,
                ConditionType::EXTERNAL => false === $internal,
                ConditionType::EXTERNAL_OR_NOBODY => true !== $internal,
                default => false,
            },
            ConditionType::Place => \in_array($issue->getPlace(), $condition->texts('is'), true),
            ConditionType::Criticality => \in_array($issue->getCriticality()->value, $condition->texts('is'), true),
            // an issue being created has no id, and no code yet
            ConditionType::Code => (null !== $issue->getId() && $this->codeReferences->hasAny($issue)) === (ConditionType::SOME === $expected),
        };
    }
}
