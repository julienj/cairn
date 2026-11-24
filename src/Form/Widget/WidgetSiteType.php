<?php

declare(strict_types=1);

namespace App\Form\Widget;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A site the widget of a project is shown on: its address.
 *
 * @extends AbstractType<WidgetSiteData>
 */
final class WidgetSiteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('address', TextType::class, [
            'label' => 'widget.site.form.address',
            'help' => 'widget.site.form.address_help',
            'attr' => ['placeholder' => 'https://', 'inputmode' => 'url', 'autocomplete' => 'off'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => WidgetSiteData::class]);
    }
}
