<?php

declare(strict_types=1);

namespace App\Form\Issue;

use App\Config\Definition\InstanceDefinition;
use App\Config\Definition\WorkflowDefinition;
use App\Entity\Enum\Criticality;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\User;
use App\Form\Type\MarkdownType;
use App\Form\Type\ToggleType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The new issue form. The fields of the tracker's workflow are built from
 * the configuration in force; what the viewer may qualify (criticality,
 * assignee, privacy) is decided by the controller through the options.
 *
 * @extends AbstractType<IssueData>
 */
final class IssueType extends AbstractType
{
    /** What starts the value of a team among the assignees, before its id: a person's value is their id alone. */
    private const string TEAM = 'team-';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $workflow = $options['workflow'];
        $definition = $options['definition'];
        \assert($workflow instanceof WorkflowDefinition && $definition instanceof InstanceDefinition);

        $builder
            ->add('title', TextType::class, [
                'label' => 'issue.field.title',
                'attr' => ['placeholder' => 'issue.field.title_placeholder', 'autofocus' => true],
            ])
            ->add('description', MarkdownType::class, [
                'label' => 'issue.field.description',
                'attr' => ['placeholder' => 'issue.field.description_placeholder', 'rows' => 8],
                'project' => $options['project'],
            ]);

        $builder->add('fields', IssueFieldsType::class, [
            'workflow' => $workflow,
            'definition' => $definition,
            'internal' => $options['internal'],
        ]);

        if ($options['criticality']) {
            $builder->add('criticality', EnumType::class, [
                'class' => Criticality::class,
                'choices' => [Criticality::Blocking, Criticality::High, Criticality::Normal, Criticality::Low],
                'choice_label' => static fn (Criticality $criticality): string => 'criticality.'.$criticality->value,
                'expanded' => true,
                'label' => 'issue.field.criticality',
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
                'label' => 'issue.field.tags',
            ]);
        }
        if (null !== $options['members']) {
            $builder->add('assignee', ChoiceType::class, [
                // The people, then the teams under their own heading: one or the other.
                'choices' => [...$options['members'], ...$options['teams']],
                'choice_label' => static fn (User|Team $assignee): string => $assignee instanceof Team ? $assignee->getName() : $assignee->getFullName(),
                'choice_value' => static fn (User|Team|null $assignee): ?string => match (true) {
                    null === $assignee => null,
                    $assignee instanceof Team => self::TEAM.$assignee->getId(),
                    default => (string) $assignee->getId(),
                },
                'group_by' => static fn (User|Team $assignee): ?string => $assignee instanceof Team ? $options['teams_label'] : null,
                'choice_translation_domain' => false,
                'placeholder' => 'issue.field.assignee_none',
                'required' => false,
                'label' => 'issue.field.assignee',
            ]);
        }
        if ($options['private']) {
            $builder->add('private', ToggleType::class, [
                'label' => 'issue.field.private',
                'help' => 'issue.field.private_help',
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => IssueData::class,
                'internal' => false,
                'criticality' => true,
                'members' => null,
                'teams' => [],
                'teams_label' => '',
                'tags' => [],
                'private' => false,
                'project' => null,
            ])
            ->setRequired(['workflow', 'definition'])
            ->setAllowedTypes('workflow', WorkflowDefinition::class)
            ->setAllowedTypes('definition', InstanceDefinition::class)
            ->setAllowedTypes('internal', 'bool')
            ->setAllowedTypes('criticality', 'bool')
            ->setAllowedTypes('members', ['null', User::class.'[]'])
            ->setAllowedTypes('teams', Team::class.'[]')
            ->setAllowedTypes('teams_label', 'string')
            ->setAllowedTypes('tags', Tag::class.'[]')
            ->setAllowedTypes('private', 'bool')
            ->setAllowedTypes('project', ['null', Project::class])
            ->setInfo('internal', 'whether internal-only fields are shown')
            ->setInfo('members', 'the people the issue may be assigned to, null when the viewer may not assign')
            ->setInfo('teams', 'the teams the issue may be assigned to, offered with the people')
            ->setInfo('teams_label', 'the heading of the teams among the choices, already translated')
            ->setInfo('tags', 'the tags of the project the issue may be created with, none when it has none or the viewer may not tag')
            ->setInfo('private', 'whether the viewer may make the issue private')
            ->setInfo('project', 'where the description editor suggests issues and people and sends images');
    }
}
