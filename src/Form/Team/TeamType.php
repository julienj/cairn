<?php

declare(strict_types=1);

namespace App\Form\Team;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Creating a team asks whether it is internal, which never changes
 * afterwards; its settings change the name.
 *
 * @extends AbstractType<TeamData>
 */
final class TeamType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'admin.team.name',
            'attr' => ['autofocus' => $options['creation']],
        ]);
        if ($options['creation']) {
            $builder->add('nature', ChoiceType::class, [
                'choices' => [
                    'admin.team.nature.internal' => TeamData::INTERNAL,
                    'admin.team.nature.external' => TeamData::EXTERNAL,
                ],
                'expanded' => true,
                'label' => 'admin.team.nature.label',
                'help' => 'admin.team.nature.help',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => TeamData::class,
                'creation' => false,
            ])
            ->setAllowedTypes('creation', 'bool');
    }
}
