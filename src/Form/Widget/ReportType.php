<?php

declare(strict_types=1);

namespace App\Form\Widget;

use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Tag;
use App\Entity\Tracker;
use App\Form\Issue\IssueFieldsType;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The form of the widget: what it takes to report a problem from the
 * site being reviewed, and no more. The category is a field of the form
 * (the page is one, redrawn when it changes), of the workflow fields only
 * those it requires are asked, and what the reporter may qualify
 * (criticality, tags, privacy) is decided by the controller through the
 * options, as for the form of the application. The description is plain
 * markdown: the editor of the application lives in its session.
 *
 * @extends AbstractType<ReportData>
 */
final class ReportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $workflow = $options['workflow'];
        $definition = $options['definition'];
        \assert($workflow instanceof WorkflowDefinition && $definition instanceof InstanceDefinition);

        $builder
            ->add('tracker', ChoiceType::class, [
                'choices' => $options['trackers'],
                'choice_label' => static fn (Tracker $tracker): string => $tracker->getName(),
                'choice_value' => static fn (?Tracker $tracker): ?string => null === $tracker ? null : (string) $tracker->getId(),
                'choice_translation_domain' => false,
                'label' => 'widget.form.tracker',
            ])
            ->add('title', TextType::class, ['label' => 'widget.form.title'])
            ->add('description', TextareaType::class, [
                'label' => 'widget.form.description',
                'attr' => ['rows' => 4],
            ])
            ->add('fields', IssueFieldsType::class, [
                'workflow' => $workflow,
                'definition' => $definition,
                'internal' => $options['internal'],
                'required_only' => true,
            ]);

        if ($options['criticality']) {
            $builder->add('criticality', EnumType::class, [
                'class' => Criticality::class,
                'choices' => [Criticality::Low, Criticality::Normal, Criticality::High, Criticality::Blocking],
                'choice_label' => static fn (Criticality $criticality): string => 'criticality.'.$criticality->value,
                'expanded' => true,
                'label' => 'widget.form.criticality',
            ]);
        }
        if ([] !== $options['tags']) {
            $builder->add('tags', ChoiceType::class, [
                'choices' => $options['tags'],
                'choice_label' => static fn (Tag $tag): string => $tag->getName(),
                'choice_value' => static fn (?Tag $tag): ?string => null === $tag ? null : (string) $tag->getId(),
                'choice_attr' => static fn (Tag $tag): array => ['data-colour' => $tag->getColour()->value],
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'label' => 'widget.form.tags',
            ]);
        }
        if ($options['private']) {
            $builder->add('private', ToggleType::class, [
                'label' => 'widget.form.private',
                'help' => 'widget.form.private_help',
                'required' => false,
            ]);
        }

        $builder
            ->add('technical', HiddenType::class, ['required' => false])
            ->add('includeReport', CheckboxType::class, ['label' => 'widget.report.include', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => ReportData::class,
                // No cookie signs a request of the widget in: there is nothing a forged request could ride on.
                'csrf_protection' => false,
                'internal' => false,
                'criticality' => false,
                'tags' => [],
                'private' => false,
            ])
            ->setRequired(['trackers', 'workflow', 'definition'])
            ->setAllowedTypes('trackers', Tracker::class.'[]')
            ->setAllowedTypes('workflow', WorkflowDefinition::class)
            ->setAllowedTypes('definition', InstanceDefinition::class)
            ->setAllowedTypes('internal', 'bool')
            ->setAllowedTypes('criticality', 'bool')
            ->setAllowedTypes('tags', Tag::class.'[]')
            ->setAllowedTypes('private', 'bool')
            ->setInfo('trackers', 'the trackers the reporter may file into')
            ->setInfo('workflow', 'the workflow of the tracker the form is drawn for')
            ->setInfo('internal', 'whether internal-only fields are shown')
            ->setInfo('tags', 'the tags of the project the issue may be created with, none when it has none or the reporter may not tag')
            ->setInfo('private', 'whether the reporter may make the issue private');
    }
}
