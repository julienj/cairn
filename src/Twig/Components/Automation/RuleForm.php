<?php

declare(strict_types=1);

namespace App\Twig\Components\Automation;

use App\Automation\Definition\RuleContext;
use App\Automation\Definition\Target;
use App\Automation\RuleContexts;
use App\Automation\RuleSentence;
use App\Entity\AutomationRule;
use App\Entity\Project;
use App\Form\Automation\RuleData;
use App\Message\Command\Automation\CreateRule;
use App\Message\Command\Automation\DeleteRule;
use App\Message\Command\Automation\UpdateRule;
use App\Message\CommandBus;
use App\Security\Permission;
use App\Security\Referents;
use App\Security\Viewer;
use App\Twig\Components\DispatchesCommands;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PostHydrate;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The editor of an automation rule of a project, a new one when `rule`
 * is null: its name, the tracker it is limited to, then the trigger, the
 * conditions and the actions. What a row asks for depends on its type,
 * and what a place or an action of a workflow offers on the tracker, so
 * the form is redrawn at each change (EditsRule, shared with the editor
 * of a rule of a project template). Beside it, the rule in one
 * sentence. Nothing is kept until `save`,
 * which dispatches CreateRule or UpdateRule and leads back to the list;
 * deleting confirms in place.
 */
#[AsLiveComponent('Automation:RuleForm')]
final class RuleForm extends AbstractController
{
    use ComponentToolsTrait;
    use DefaultActionTrait;
    use DispatchesCommands;
    use EditsRule;

    #[LiveProp]
    public Project $project;

    #[LiveProp]
    public ?AutomationRule $rule = null;

    public function __construct(
        private readonly RuleContexts $contexts,
        private readonly RuleSentence $sentence,
        private readonly Referents $referents,
        private readonly FormFactoryInterface $forms,
        private readonly TranslatorInterface $translator,
        private readonly Viewer $viewer,
    ) {
    }

    /**
     * The editor shows the private trackers and the members of the
     * project: it is drawn, and redrawn, only for who writes its rules,
     * whatever page or request holds it. The commands guard the writing.
     */
    #[PostMount]
    #[PostHydrate]
    public function guard(): void
    {
        $this->denyAccessUnlessGranted(Permission::AutomationManage->value, $this->project);
        if (null !== $this->rule && $this->rule->getProject()->getId() !== $this->project->getId()) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * Who a target that names a referent leads to today, null for any
     * other target: their `name`, and whether they `answer` (someone may
     * be designated whose account is deactivated, or who holds another
     * role since: the action is then skipped).
     *
     * @return array{name: string, answers: bool}|null
     */
    public function referentToday(string $target): ?array
    {
        $parsed = Target::parse($target);
        if (Target::REFERENT !== $parsed?->kind) {
            return null;
        }
        $referent = $this->referents->of($this->project, $parsed->argument);

        return ['name' => (string) ($referent ?? $this->referents->marked($this->project)[$parsed->argument] ?? null)?->getFullName(), 'answers' => null !== $referent];
    }

    #[LiveAction]
    public function save(CommandBus $commands): ?Response
    {
        $this->submitForm();
        $data = $this->getForm()->getData();
        \assert($data instanceof RuleData);
        $actorId = $this->viewer->get()->getId();

        $command = null === $this->rule
            ? new CreateRule((int) $this->project->getId(), (string) $data->name, $data->trackerId(), $data->definition(), actorId: $actorId)
            : new UpdateRule((int) $this->rule->getId(), name: (string) $data->name, trackerId: $data->trackerId() ?? UpdateRule::NO_TRACKER, definition: $data->definition(), actorId: $actorId);
        if (!$this->tryCommand($commands, $command)) {
            return null;
        }
        $this->addFlash('success', $this->translator->trans(null === $this->rule ? 'automation.created' : 'automation.saved'));

        return $this->backToTheList();
    }

    #[LiveAction]
    public function delete(CommandBus $commands): ?Response
    {
        $this->deleting = false;
        if (null === $this->rule || !$this->tryCommand($commands, new DeleteRule((int) $this->rule->getId(), $this->viewer->get()->getId()))) {
            return null;
        }
        $this->addFlash('success', $this->translator->trans('automation.deleted'));

        return $this->backToTheList();
    }

    /**
     * Where Cancel leads: the rules of the project.
     */
    public function getCancelUrl(): string
    {
        return $this->generateUrl('app_project_settings_automations', ['workspaceKey' => $this->project->getWorkspace()->getKey(), 'projectKey' => $this->project->getKey()]);
    }

    protected function ruleContext(): RuleContext
    {
        return $this->contexts->ofProject($this->project);
    }

    protected function storedRule(): ?AutomationRule
    {
        return $this->rule;
    }

    protected function ruleSentence(): RuleSentence
    {
        return $this->sentence;
    }

    protected function formFactory(): FormFactoryInterface
    {
        return $this->forms;
    }

    private function backToTheList(): Response
    {
        return $this->redirect($this->getCancelUrl());
    }
}
