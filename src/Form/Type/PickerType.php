<?php

declare(strict_types=1);

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One choice among many (the projects of the instance): a field that
 * opens a list narrowed by a search, instead of a select to scroll. A
 * ChoiceType in every other respect, groups included. Rendered by the
 * `picker_widget` block of the form theme, which calls Ui:Picker; the
 * `picker` controller writes the choice into the hidden field.
 *
 * The list is in the page and narrowed by the browser, which suits what
 * an instance has few of. For what has no bound (the issues of a
 * project), `search_url` names an address that answers the items
 * matching `q` as HTML, asked as the user types; the field then comes
 * with a SearchedChoiceLoader as its `choice_loader`, which looks up the
 * value submitted instead of loading a list.
 *
 * `avatars` is for a choice among people and teams: each line shows who
 * it is, as every list of people does, and so does the button once one
 * is chosen.
 *
 * @extends AbstractType<mixed>
 */
final class PickerType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('search_url', null);
        $resolver->setAllowedTypes('search_url', ['null', 'string']);
        $resolver->setDefault('avatars', false);
        $resolver->setAllowedTypes('avatars', 'bool');
        $resolver->setInfo('avatars', 'whether the choices are people or teams, drawn with their faces (User:Line, Team:Line)');
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['search_url'] = $options['search_url'];
        $view->vars['avatars'] = $options['avatars'];
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    public function getBlockPrefix(): string
    {
        return 'picker';
    }
}
