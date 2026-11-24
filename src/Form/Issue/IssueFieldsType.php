<?php

declare(strict_types=1);

namespace App\Form\Issue;

use App\Config\Definition\FieldDefinition;
use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Config\FieldType;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The fields of a workflow, built from the configuration in force, as
 * `field id => value`. Used at creation (inside IssueType) and on the
 * issue page.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class IssueFieldsType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $workflow = $options['workflow'];
        $definition = $options['definition'];
        \assert($workflow instanceof WorkflowDefinition && $definition instanceof InstanceDefinition);

        foreach ($workflow->fields as $usage) {
            $field = $definition->fields[$usage->field];
            if ($field->internalOnly && !$options['internal']) {
                continue;
            }
            if ($options['required_only'] && !$usage->required) {
                continue;
            }
            [$type, $typeOptions] = $this->control($field);
            $builder->add($field->id, $type, $typeOptions + [
                // Configuration labels are content, never translated.
                'translation_domain' => false,
                'label' => $field->label,
                'help' => $field->help,
                'required' => $usage->required,
                'constraints' => [
                    ...($usage->required && FieldType::Bool !== $field->type ? [new NotBlank()] : []),
                    // Shown as a link: only a web address is one.
                    ...(FieldType::Url === $field->type ? [new Url(protocols: ['http', 'https'], requireTld: false)] : []),
                ],
                'attr' => null === $field->placeholder ? [] : ['placeholder' => $field->placeholder],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults(['label' => false, 'internal' => false, 'required_only' => false])
            ->setRequired(['workflow', 'definition'])
            ->setAllowedTypes('workflow', WorkflowDefinition::class)
            ->setAllowedTypes('definition', InstanceDefinition::class)
            ->setAllowedTypes('internal', 'bool')
            ->setAllowedTypes('required_only', 'bool')
            ->setInfo('internal', 'whether internal-only fields are shown')
            ->setInfo('required_only', 'whether only the fields the workflow requires are asked (the widget)');
    }

    /**
     * The form type behind a configured field type.
     *
     * @return array{class-string<AbstractType<mixed>>, array<string, mixed>}
     */
    private function control(FieldDefinition $field): array
    {
        return match ($field->type) {
            FieldType::Text => [TextType::class, []],
            // The rich-text editor is a React island to come; a textarea meanwhile.
            FieldType::Textarea, FieldType::RichText => [TextareaType::class, ['attr' => ['rows' => 3]]],
            FieldType::Select => [ChoiceType::class, [
                'choices' => array_flip($field->options),
                'choice_translation_domain' => false,
                'placeholder' => $this->translator->trans('form.choose'),
            ]],
            FieldType::Choice => [ChoiceType::class, [
                'choices' => array_flip($field->options),
                'choice_translation_domain' => false,
                'expanded' => true,
                // No "none" segment: leaving every segment off is the empty value.
                'placeholder' => false,
            ]],
            FieldType::Number => [NumberType::class, ['html5' => true]],
            FieldType::Date => [DateType::class, ['widget' => 'single_text', 'input' => 'string']],
            FieldType::Url => [UrlType::class, ['default_protocol' => null]],
            FieldType::Bool => [ToggleType::class, []],
        };
    }
}
