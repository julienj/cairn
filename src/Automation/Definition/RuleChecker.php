<?php

declare(strict_types=1);

namespace App\Automation\Definition;

use App\Automation\AutomationException;
use App\Entity\Enum\Criticality;

/**
 * Says whether a rule means something where it is written: every value
 * is one its kind allows in the context, a step that names a place or an
 * action of a workflow comes with a tracker, a rule has something to do.
 * The one place that decides it, for the commands that keep a rule of a
 * project and those that keep a rule of a project template. A rule that passed may stop meaning something
 * (a place leaves the configuration, a member the project): the engine
 * then skips what it cannot do and says why, nothing checks again here.
 */
final class RuleChecker
{
    public const int MAX_DAYS = 3650;
    public const int MAX_TEXT = 10_000;
    public const int MAX_STEPS = 20;

    /**
     * @param string|null $tracker the key of the tracker the rule is limited to
     */
    public function check(RuleDefinition $definition, ?string $tracker, RuleContext $context): void
    {
        if (null !== $tracker && !isset($context->trackers[$tracker])) {
            throw AutomationException::unknownTracker();
        }
        if ([] === $definition->actions) {
            throw AutomationException::noAction();
        }
        if (\count($definition->conditions) > self::MAX_STEPS || \count($definition->actions) > self::MAX_STEPS) {
            throw AutomationException::tooManySteps(self::MAX_STEPS);
        }
        if (null === $tracker && $definition->needsTracker()) {
            throw AutomationException::needsTracker();
        }

        foreach ([$definition->trigger, ...$definition->conditions, ...$definition->actions] as $step) {
            if ($definition->trigger->type->isTimed() && ConditionType::Actor === $step->type) {
                throw AutomationException::nobodyActs();
            }
            foreach ($step->type->parameters() as $parameter) {
                $value = $step->params[$parameter->name] ?? null;
                if (!$parameter->required && (null === $value || '' === $value || false === $value)) {
                    continue;
                }
                if (!self::allows($parameter, $value, $step->type, $tracker, $context)) {
                    throw AutomationException::invalidValue($step->type->value, $parameter->name);
                }
            }
        }
    }

    /**
     * Whether a value is one the parameter of a step may take in the
     * context: asked for every value of a rule to keep, and by the form
     * for the choices it still holds (RuleData).
     *
     * @param string|null $tracker the key of the tracker the rule is limited to
     */
    public static function allows(Parameter $parameter, mixed $value, TriggerType|ConditionType|ActionType $owner, ?string $tracker, RuleContext $context): bool
    {
        if (!$parameter->multiple) {
            return self::allowsOne($parameter, $value, $owner, $tracker, $context);
        }
        // A single value is a list of one: a rule written before the parameter took several.
        $values = \is_array($value) ? $value : [$value];

        return [] !== $values && array_all($values, static fn (mixed $one): bool => self::allowsOne($parameter, $one, $owner, $tracker, $context));
    }

    /**
     * Whether one value is one the parameter may take, among several when
     * it takes a list.
     *
     * @param string|null $tracker the key of the tracker the rule is limited to
     */
    public static function allowsOne(Parameter $parameter, mixed $value, TriggerType|ConditionType|ActionType $owner, ?string $tracker, RuleContext $context): bool
    {
        $workflow = $context->workflow($tracker);
        $target = ParameterKind::Target === $parameter->kind ? Target::parse($value) : null;

        return match ($parameter->kind) {
            ParameterKind::Choice => \is_string($value) && \in_array($value, $parameter->options, true),
            ParameterKind::Days => \is_int($value) && $value >= 1 && $value <= self::MAX_DAYS,
            ParameterKind::Place => \is_string($value) && isset($context->places($tracker)[$value]),
            ParameterKind::ScopedPlace => \is_string($value) && null !== $workflow && $workflow->hasPlace($value),
            ParameterKind::Transition => \is_string($value) && null !== $workflow && isset($workflow->transitions[$value]),
            ParameterKind::Criticality => \is_string($value) && null !== Criticality::tryFrom($value),
            ParameterKind::Target => match ($target?->kind) {
                null => false,
                Target::REFERENT => isset($context->roles[$target->argument]),
                Target::USER => isset($context->people[(int) $target->argument]),
                Target::TEAM => isset($context->teams[(int) $target->argument]),
                // an issue is assigned to nobody; nobody cannot follow one
                Target::NOBODY => ActionType::Assign === $owner,
                default => true,
            },
            ParameterKind::Text => \is_string($value) && '' !== trim($value) && mb_strlen($value) <= self::MAX_TEXT,
            ParameterKind::Flag => \is_bool($value),
        };
    }
}
