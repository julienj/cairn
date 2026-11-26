<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\ActionType;
use App\Automation\Definition\ConditionType;
use App\Automation\Definition\ParameterKind;
use App\Automation\Definition\RuleStep;
use App\Automation\Definition\Target;
use App\Automation\Definition\TriggerType;
use App\Entity\AutomationRule;
use App\Entity\TemplateRule;
use App\Repository\AutomationRuleRepository;
use App\Repository\TemplateRuleRepository;
use App\Repository\TemplateTrackerRepository;
use App\Repository\TrackerRepository;

/**
 * The rules that name something of the configuration: a step, an action
 * of a workflow, the referent of a role. A rule keeps what it says as
 * data, so nothing ties it to what it names; whoever takes a step out of
 * a workflow or deletes a role asks here first which rules it leaves
 * without their object, in the projects and in the project templates.
 *
 * Every parameter of a rule declares its kind, so the search knows none of
 * the triggers, conditions and actions: a new one is found as it is.
 */
final class RuleReferences
{
    public function __construct(
        private readonly AutomationRuleRepository $rules,
        private readonly TemplateRuleRepository $templateRules,
        private readonly TrackerRepository $trackers,
        private readonly TemplateTrackerRepository $templateTrackers,
    ) {
    }

    /**
     * The rules that name a step and hold on a tracker of the workflow:
     * those limited to one, and those without a tracker of a project (or a
     * template) that has one.
     *
     * @return list<string> each as `Rule (project or template)`
     */
    public function toPlace(string $workflowId, string $placeId): array
    {
        return $this->naming(
            static fn (ParameterKind $kind): bool => \in_array($kind, [ParameterKind::Place, ParameterKind::ScopedPlace], true),
            static fn (string $value): bool => $value === $placeId,
            $workflowId,
        );
    }

    /**
     * The rules an action of a workflow triggers.
     *
     * @return list<string>
     */
    public function toTransition(string $workflowId, string $key): array
    {
        return $this->naming(
            static fn (ParameterKind $kind): bool => ParameterKind::Transition === $kind,
            static fn (string $value): bool => $value === $key,
            $workflowId,
        );
    }

    /**
     * The rules that aim at the referent of a role, wherever they hold.
     *
     * @return list<string>
     */
    public function toRole(string $roleId): array
    {
        return $this->naming(
            static fn (ParameterKind $kind): bool => ParameterKind::Target === $kind,
            static function (string $value) use ($roleId): bool {
                $target = Target::parse($value);

                return Target::REFERENT === $target?->kind && $target->argument === $roleId;
            },
            null,
        );
    }

    /**
     * The rules that aim at a team, wherever they hold.
     *
     * @return list<string>
     */
    public function toTeam(int $teamId): array
    {
        return $this->naming(
            static fn (ParameterKind $kind): bool => ParameterKind::Target === $kind,
            static function (string $value) use ($teamId): bool {
                $target = Target::parse($value);

                return Target::TEAM === $target?->kind && (int) $target->argument === $teamId;
            },
            null,
        );
    }

    /**
     * @param \Closure(ParameterKind): bool $kind       the parameters to look at
     * @param \Closure(string): bool        $names      whether a value of one is what is looked for
     * @param string|null                   $workflowId only the rules that hold on a tracker of this workflow
     *
     * @return list<string>
     */
    private function naming(\Closure $kind, \Closure $names, ?string $workflowId): array
    {
        $found = [];
        foreach ([...$this->rules->findAll(), ...$this->templateRules->findAll()] as $rule) {
            if (null !== $workflowId && !$this->holdsOn($rule, $workflowId)) {
                continue;
            }
            try {
                $definition = $rule->getDefinition();
            } catch (AutomationException) {
                // A rule that can no longer be read names nothing, and runs nowhere.
                continue;
            }
            foreach ([$definition->trigger, ...$definition->conditions, ...$definition->actions] as $step) {
                if (self::stepNames($step, $kind, $names)) {
                    $found[] = \sprintf('%s (%s)', $rule->getName(), $rule instanceof AutomationRule ? $rule->getProject()->getName() : $rule->getTemplate()->getName());
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * @param RuleStep<TriggerType>|RuleStep<ConditionType>|RuleStep<ActionType> $step
     * @param \Closure(ParameterKind): bool                                      $kind
     * @param \Closure(string): bool                                             $names
     */
    private static function stepNames(RuleStep $step, \Closure $kind, \Closure $names): bool
    {
        foreach ($step->type->parameters() as $parameter) {
            if ($kind($parameter->kind) && array_any($step->texts($parameter->name), $names)) {
                return true;
            }
        }

        return false;
    }

    private function holdsOn(AutomationRule|TemplateRule $rule, string $workflowId): bool
    {
        $tracker = $rule->getTracker();
        if (null !== $tracker) {
            return $tracker->getWorkflowId() === $workflowId;
        }

        $trackers = $rule instanceof AutomationRule
            ? $this->trackers->findByProject($rule->getProject(), includeArchived: true)
            : $this->templateTrackers->findByTemplate($rule->getTemplate());

        return array_any($trackers, static fn ($each): bool => $each->getWorkflowId() === $workflowId);
    }
}
