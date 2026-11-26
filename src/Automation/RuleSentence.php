<?php

declare(strict_types=1);

namespace App\Automation;

use App\Automation\Definition\ActionType;
use App\Automation\Definition\ConditionType;
use App\Automation\Definition\RuleContext;
use App\Automation\Definition\RuleDefinition;
use App\Automation\Definition\RuleStep;
use App\Automation\Definition\Target;
use App\Automation\Definition\TriggerType;
use App\Config\Definition\WorkflowDefinition;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Says a rule in one sentence, in the language of the instance: "When a
 * comment is added, if someone of the client did it: assign it to the
 * Project manager referent." Read beside the form while a rule is
 * written, and in the list of rules instead of opening each one. A value
 * not chosen yet, or that no longer exists, reads as "…".
 */
final class RuleSentence
{
    private const string BLANK = '…';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @param string|null $tracker the key of the tracker the rule is limited to
     */
    public function of(RuleDefinition $definition, ?string $tracker, RuleContext $context): string
    {
        $workflow = $context->workflow($tracker);
        $places = $context->places($tracker);
        $conditions = array_map(fn (RuleStep $condition): string => $this->condition($condition, $places), $definition->conditions);
        $actions = array_map(fn (RuleStep $action): string => $this->action($action, $tracker, $context), $definition->actions);

        return $this->when($definition->trigger, $workflow, $places)
            .([] === $conditions ? '' : $this->join('if').implode($this->join('and'), $conditions))
            .$this->join('then')
            .([] === $actions ? $this->join('nothing') : implode($this->join('next'), $actions))
            .'.';
    }

    /**
     * @param RuleStep<TriggerType> $trigger
     * @param array<string, string> $places  place id => label
     */
    private function when(RuleStep $trigger, ?WorkflowDefinition $workflow, array $places): string
    {
        $key = 'automation.sentence.when.'.$trigger->type->value;

        return match ($trigger->type) {
            TriggerType::TransitionApplied => $this->translator->trans($key, ['%transition%' => ($workflow?->transitions[$trigger->text('transition')] ?? null)->label ?? self::BLANK]),
            TriggerType::PlaceChanged => '' === $trigger->text('place')
                ? $this->translator->trans($key.'_any')
                : $this->translator->trans($key, ['%place%' => $places[$trigger->text('place')] ?? self::BLANK]),
            TriggerType::InactiveFor => $this->translator->trans($key, ['%count%' => max(1, (int) $trigger->text('days'))]),
            default => $this->translator->trans($key),
        };
    }

    /**
     * @param RuleStep<ConditionType> $condition
     * @param array<string, string>   $places    place id => label
     */
    private function condition(RuleStep $condition, array $places): string
    {
        $key = 'automation.sentence.if.'.$condition->type->value;
        $is = $condition->text('is');

        return match ($condition->type) {
            ConditionType::Place => $this->translator->trans($key, ['%places%' => implode($this->join('or'), array_map(static fn (string $place): string => '“'.($places[$place] ?? self::BLANK).'”', $condition->texts('is'))) ?: self::BLANK]),
            ConditionType::Criticality => $this->translator->trans($key, ['%criticality%' => implode($this->join('or'), array_map($this->criticality(...), $condition->texts('is'))) ?: self::BLANK]),
            default => '' === $is ? self::BLANK : $this->translator->trans($key.'.'.$is),
        };
    }

    /**
     * @param RuleStep<ActionType> $action
     */
    private function action(RuleStep $action, ?string $tracker, RuleContext $context): string
    {
        $key = 'automation.sentence.then.'.$action->type->value;

        return match ($action->type) {
            ActionType::Assign => Target::NOBODY === $action->text('target')
                ? $this->translator->trans('automation.sentence.then.unassign')
                : $this->translator->trans($key, ['%target%' => $this->target($action->text('target'), $context)]),
            ActionType::Subscribe => $this->translator->trans($key, ['%target%' => $this->target($action->text('target'), $context)]),
            ActionType::Move => $this->translator->trans($key, ['%place%' => $context->placesOf($tracker)[$action->text('place')] ?? self::BLANK]),
            ActionType::SetCriticality => $this->translator->trans($key, ['%criticality%' => $this->criticality($action->text('criticality'))]),
            ActionType::Comment => $this->translator->trans($action->flag('private') ? $key.'_private' : $key),
            ActionType::MakePrivate => $this->translator->trans($key),
            ActionType::MarkBudgetLines => $this->translator->trans($key, [
                '%from%' => implode($this->join('or'), array_map($this->budgetStatus(...), $action->texts('from'))) ?: self::BLANK,
                '%status%' => $this->budgetStatus($action->text('status')),
            ]),
        };
    }

    private function target(string $target, RuleContext $context): string
    {
        $parsed = Target::parse($target);

        return match ($parsed?->kind) {
            null => self::BLANK,
            Target::REFERENT => $this->translator->trans('automation.sentence.target.referent', ['%role%' => $context->roles[$parsed->argument] ?? self::BLANK]),
            Target::USER => $context->people[(int) $parsed->argument] ?? self::BLANK,
            Target::TEAM => $this->translator->trans('automation.sentence.target.team', ['%team%' => $context->teams[(int) $parsed->argument] ?? self::BLANK]),
            default => $this->translator->trans('automation.sentence.target.'.$parsed->kind),
        };
    }

    private function criticality(string $value): string
    {
        return '' === $value ? self::BLANK : mb_strtolower($this->translator->trans('criticality.'.$value));
    }

    private function budgetStatus(string $value): string
    {
        return '' === $value ? self::BLANK : '“'.$this->translator->trans('budget.status.'.$value).'”';
    }

    private function join(string $piece): string
    {
        return $this->translator->trans('automation.sentence.join.'.$piece);
    }
}
