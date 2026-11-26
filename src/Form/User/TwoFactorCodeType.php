<?php

declare(strict_types=1);

namespace App\Form\User;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TwoFactorCodeData>
 */
final class TwoFactorCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'label' => $options['code_label'],
            'attr' => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'spellcheck' => 'false'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TwoFactorCodeData::class,
            'code_label' => 'account.two_factor.code',
        ]);
        $resolver->setAllowedTypes('code_label', 'string');
    }
}
