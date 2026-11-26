<?php

declare(strict_types=1);

namespace App\Form\Automation;

use App\Automation\Definition\ActionType;
use App\Automation\Definition\ConditionType;
use App\Automation\Definition\Parameter;
use App\Automation\Definition\ParameterKind;
use App\Automation\Definition\RuleChecker;
use App\Automation\Definition\RuleContext;
use App\Automation\Definition\Target;
use App\Automation\Definition\TriggerType;
use App\Entity\Enum\Criticality;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The form of an automation rule, drawn from the rule as the page holds
 * it (`rule`): the trigger, then one row per condition and per action.
 * A row is the choice of its type followed by one field per parameter
 * that type declares, the field being decided by the kind of the
 * parameter alone: a new type of step needs nothing here. What a field
 * offers (trackers, places, roles, people) comes from the `context`, the
 * project today. The labels of the configuration and the names are
 * content; ours are translated here, so no choice goes to the translator
 * again. Every choice is offered by its value and labelled beside
 * (`choice()`): two people, or two trackers, may bear the same name.
 *
 * @extends AbstractType<RuleData>
 */
final class RuleType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $context = $options['context'];
        $rule = $options['rule'];
        \assert($context instanceof RuleContext && $rule instanceof RuleData);
        $tracker = '' === (string) $rule->tracker ? null : (string) $rule->tracker;

        $triggers = [];
        foreach (TriggerType::cases() as $type) {
            $triggers[$this->translator->trans('automation.trigger_group.'.($type->isTimed() ? 'timed' : 'event'))][$type->value] = $this->translator->trans('automation.trigger.'.$type->value);
        }
        $conditions = $actions = [];
        foreach (ConditionType::cases() as $type) {
            $conditions[$type->value] = $this->translator->trans('automation.condition.'.$type->value);
        }
        foreach (ActionType::cases() as $type) {
            $actions[$type->value] = $this->translator->trans('automation.action.'.$type->value);
        }

        $builder
            ->add('name', TextType::class, ['label' => 'automation.form.name', 'help' => 'automation.form.name_help'])
            ->add('tracker', ChoiceType::class, ['label' => 'automation.form.tracker', 'placeholder' => 'automation.all_trackers'] + $this->choice($context->trackers, required: false))
            ->add($this->step($builder, 'trigger', $rule->trigger, TriggerType::class, $triggers, $tracker, $context));

        $rows = $builder->create('conditions', FormType::class, ['label' => false]);
        foreach ($rule->conditions as $index => $row) {
            $rows->add($this->step($builder, (string) $index, $row, ConditionType::class, $conditions, $tracker, $context));
        }
        $builder->add($rows);

        $rows = $builder->create('actions', FormType::class, ['label' => false]);
        foreach ($rule->actions as $index => $row) {
            $rows->add($this->step($builder, (string) $index, $row, ActionType::class, $actions, $tracker, $context));
        }
        $builder->add($rows);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => RuleData::class]);
        $resolver->setRequired(['context', 'rule']);
        $resolver->setAllowedTypes('context', RuleContext::class);
        $resolver->setAllowedTypes('rule', RuleData::class);
    }

    /**
     * One row: the choice of its type, then the fields of that type under `params`, as a step is kept.
     *
     * @template T of TriggerType|ConditionType|ActionType
     *
     * @param FormBuilderInterface<mixed>                       $builder
     * @param array<string, mixed>                              $row
     * @param class-string<T>                                   $enum
     * @param array<array-key, string|array<array-key, string>> $types   what the row may be, value => label, possibly grouped
     *
     * @return FormBuilderInterface<mixed>
     */
    private function step(FormBuilderInterface $builder, string $name, array $row, string $enum, array $types, ?string $tracker, RuleContext $context): FormBuilderInterface
    {
        $type = $enum::tryFrom(\is_string($row['type'] ?? null) ? $row['type'] : '') ?? $enum::cases()[0];
        $params = $builder->create('params', FormType::class, ['label' => false]);
        foreach ($type->parameters() as $parameter) {
            [$field, $fieldOptions] = $this->field($parameter, $type, $tracker, $context);
            $params->add($parameter->name, $field, $fieldOptions);
        }

        return $builder->create($name, FormType::class, ['label' => false])
            ->add('type', ChoiceType::class, ['placeholder' => false] + $this->choice($types))
            ->add($params);
    }

    /**
     * The field of a parameter, by its kind.
     *
     * @return array{class-string<FormTypeInterface<mixed>>, array<string, mixed>}
     */
    private function field(Parameter $parameter, TriggerType|ConditionType|ActionType $owner, ?string $tracker, RuleContext $context): array
    {
        $workflow = $context->workflow($tracker);
        $choices = match ($parameter->kind) {
            ParameterKind::Choice => array_combine($parameter->options, array_map(fn (string $option): string => $this->translator->trans('automation.option.'.$owner->value.'.'.$option), $parameter->options)),
            ParameterKind::Place => $context->places($tracker),
            ParameterKind::ScopedPlace => $context->placesOf($tracker),
            ParameterKind::Transition => array_map(static fn ($transition): string => $transition->label, $workflow->transitions ?? []),
            ParameterKind::Criticality => array_combine(
                array_map(static fn (Criticality $criticality): string => $criticality->value, Criticality::cases()),
                array_map(fn (Criticality $criticality): string => $this->translator->trans('criticality.'.$criticality->value), Criticality::cases()),
            ),
            ParameterKind::Target => $this->targets($owner, $context),
            default => null,
        };

        return match (true) {
            // A place or an action of a workflow has nothing to offer until the rule is limited to a tracker.
            $parameter->kind->needsTracker() && null === $workflow => [ChoiceType::class, ['placeholder' => $this->translator->trans('automation.form.needs_tracker'), 'disabled' => true] + $this->choice([], required: false)],
            // Several values: one box each, at least one checked.
            $parameter->multiple => [ChoiceType::class, ['multiple' => true, 'expanded' => true, 'constraints' => [new Count(min: 1)]] + $this->choice($choices ?? [])],
            null !== $choices => [ChoiceType::class, [
                'placeholder' => $this->translator->trans($parameter->required ? 'automation.form.choose' : 'automation.form.any_place'),
                'constraints' => $parameter->required ? [new NotBlank()] : [],
            ] + $this->choice($choices, $parameter->required)],
            ParameterKind::Days === $parameter->kind => [IntegerType::class, [
                'label' => false,
                'attr' => ['min' => 1, 'max' => RuleChecker::MAX_DAYS, 'aria-label' => $this->translator->trans('automation.form.days')],
                'constraints' => [new NotBlank(), new Range(min: 1, max: RuleChecker::MAX_DAYS)],
            ]],
            ParameterKind::Text === $parameter->kind => [TextareaType::class, [
                'label' => false,
                'attr' => ['rows' => 4, 'aria-label' => $this->translator->trans('automation.form.body')],
                'constraints' => [new NotBlank(normalizer: 'trim'), new Length(max: RuleChecker::MAX_TEXT)],
            ]],
            default => [CheckboxType::class, ['label' => 'automation.form.'.$parameter->name, 'required' => false]],
        };
    }

    /**
     * The options of a choice offered by value: the values are the
     * choices, their labels are looked up, so that two choices with the
     * same label stay two.
     *
     * @param array<array-key, string|array<array-key, string>> $labels value => label, or group => value => label
     *
     * @return array<string, mixed>
     */
    private function choice(array $labels, bool $required = true): array
    {
        $choices = [];
        $flat = [];
        foreach ($labels as $key => $label) {
            if (\is_array($label)) {
                $choices[$key] = array_map(strval(...), array_keys($label));
                $flat += $label;
            } else {
                $choices[] = (string) $key;
                $flat[$key] = $label;
            }
        }

        return [
            'label' => false,
            'choices' => $choices,
            'choice_label' => static fn (string $value): string => $flat[$value] ?? $value,
            'choice_translation_domain' => false,
            'required' => $required,
        ];
    }

    /**
     * Who a step may aim at: the referent of each role, who the issue
     * names, the teams, then the members where the context has some.
     *
     * @return array<string, array<string, string>> group => target => label
     */
    private function targets(TriggerType|ConditionType|ActionType $owner, RuleContext $context): array
    {
        $referents = [];
        foreach ($context->roles as $role => $label) {
            $referents[Target::referent($role)] = $this->translator->trans('automation.target.referent', ['%role%' => $label]);
        }
        $relative = [];
        foreach ([...Target::RELATIVE, ...(ActionType::Assign === $owner ? [Target::NOBODY] : [])] as $kind) {
            $relative[$kind] = $this->translator->trans('automation.target.'.$kind);
        }
        $people = [];
        foreach ($context->people as $id => $name) {
            $people[Target::user($id)] = $name;
        }

        $teams = [];
        foreach ($context->teams as $id => $name) {
            $teams[Target::team($id)] = \in_array($id, $context->idleTeams, true) ? $this->translator->trans('automation.target.team_without_role', ['%team%' => $name]) : $name;
        }

        return array_filter([
            $this->translator->trans('automation.target.group.referents') => $referents,
            $this->translator->trans('automation.target.group.issue') => $relative,
            $this->translator->trans('automation.target.group.teams') => $teams,
            $this->translator->trans('automation.target.group.people') => $people,
        ]);
    }
}
