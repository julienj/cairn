<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Automation\AutomationException;
use App\Config\ConfigRegistry;
use App\Entity\ProjectTemplate;
use App\Entity\TemplateRule;
use App\Entity\TemplateTracker;
use App\Form\Template\TemplateData;
use App\Form\Template\TemplateType;
use App\Form\Tracker\TrackerData;
use App\Form\Tracker\TrackerType;
use App\Message\Command\Template\CreateTemplate;
use App\Message\Command\Template\CreateTemplateTracker;
use App\Message\Command\Template\DeleteTemplate;
use App\Message\Command\Template\DeleteTemplateTracker;
use App\Message\Command\Template\UpdateTemplate;
use App\Message\Command\Template\UpdateTemplateRule;
use App\Message\Command\Template\UpdateTemplateTracker;
use App\Message\CommandBus;
use App\MessageHandler\Command\Template\TemplateException;
use App\Repository\ProjectTemplateRepository;
use App\Repository\TemplateRuleRepository;
use App\Repository\TemplateTrackerRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The project templates: what a new project may start with. A template
 * page has a route per tab: its trackers (one page each, the others
 * listed beside it), its automation rules (the editor of the rules of a
 * project, without anybody to name), its settings. A template is chosen
 * when a project is created (ProjectController::new()) and copied then;
 * nothing here touches a project that exists.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/templates')]
final class TemplateController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TemplateTrackerRepository $trackers,
        private readonly TemplateRuleRepository $rules,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_admin_templates', methods: ['GET'])]
    public function list(ProjectTemplateRepository $templates): Response
    {
        return $this->render('admin/templates.html.twig', [
            'templates' => $templates->findAllByName(),
            'trackers' => $this->trackers->countByTemplate(),
            'rules' => $this->rules->countByTemplate(),
        ]);
    }

    #[Route('/new', name: 'app_admin_template_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new TemplateData();
        $form = $this->createForm(TemplateType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $template = $this->commands->dispatch(new CreateTemplate((string) $data->name, $data->description));
                \assert($template instanceof ProjectTemplate);
                $this->addFlash('success', $this->translator->trans('admin.template.created', ['%name%' => $template->getName()]));

                return $this->redirectToRoute('app_admin_template', ['template' => $template->getId()]);
            } catch (TemplateException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/template_new.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * The trackers of the template: the page of the first one, or what to
     * do when it has none.
     */
    #[Route('/{template}', name: 'app_admin_template', requirements: ['template' => '\d+'], methods: ['GET'])]
    public function trackers(#[MapEntity(id: 'template')] ProjectTemplate $template): Response
    {
        $first = $this->trackers->findByTemplate($template)[0] ?? null;
        if (null !== $first) {
            return $this->redirectToRoute('app_admin_template_tracker', ['template' => $template->getId(), 'tracker' => $first->getId()]);
        }

        return $this->render('admin/template/trackers.html.twig', ['template' => $template, 'tracker' => null, 'form' => null]);
    }

    #[Route('/{template}/trackers/new', name: 'app_admin_template_tracker_new', requirements: ['template' => '\d+'], methods: ['GET', 'POST'])]
    public function newTracker(#[MapEntity(id: 'template')] ProjectTemplate $template, Request $request): Response
    {
        $data = new TrackerData();
        $form = $this->createForm(TrackerType::class, $data, ['creation' => true, 'workflows' => $this->config->definition()->workflowLabels()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $tracker = $this->commands->dispatch(new CreateTemplateTracker((int) $template->getId(), (string) $data->name, (string) $data->workflowId, $data->description, $data->private));
                \assert($tracker instanceof TemplateTracker);
                $this->addFlash('success', $this->translator->trans('project.settings.tracker.created', ['%name%' => $tracker->getName()]));

                return $this->redirectToRoute('app_admin_template_tracker', ['template' => $template->getId(), 'tracker' => $tracker->getId()]);
            } catch (TemplateException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/template/trackers.html.twig', ['template' => $template, 'tracker' => null, 'form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{template}/trackers/{tracker}', name: 'app_admin_template_tracker', requirements: ['template' => '\d+', 'tracker' => '\d+'], methods: ['GET', 'POST'])]
    public function tracker(#[MapEntity(id: 'template')] ProjectTemplate $template, #[MapEntity(id: 'tracker')] TemplateTracker $tracker, Request $request): Response
    {
        $this->ofTemplate($tracker->getTemplate(), $template);
        $data = new TrackerData();
        $data->name = $tracker->getName();
        $data->description = $tracker->getDescription();
        $data->private = $tracker->isPrivate();
        $form = $this->createForm(TrackerType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->commands->dispatch(new UpdateTemplateTracker((int) $tracker->getId(), (string) $data->name, (string) $data->description, $data->private));
            $this->addFlash('success', $this->translator->trans('admin.saved'));

            return $this->redirectToRoute('app_admin_template_tracker', ['template' => $template->getId(), 'tracker' => $tracker->getId()]);
        }

        $definition = $this->config->definition();

        return $this->render('admin/template/trackers.html.twig', [
            'template' => $template,
            'tracker' => $tracker,
            'form' => $form,
            'workflow' => $definition->workflow($tracker->getWorkflowId()),
            'ruleCount' => $this->rules->count(['tracker' => $tracker]),
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{template}/trackers/{tracker}/delete', name: 'app_admin_template_tracker_delete', requirements: ['template' => '\d+', 'tracker' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function deleteTracker(#[MapEntity(id: 'template')] ProjectTemplate $template, #[MapEntity(id: 'tracker')] TemplateTracker $tracker): Response
    {
        $this->ofTemplate($tracker->getTemplate(), $template);
        $name = $tracker->getName();
        $this->commands->dispatch(new DeleteTemplateTracker((int) $tracker->getId()));
        $this->addFlash('success', $this->translator->trans('project.settings.tracker.done.delete', ['%name%' => $name]));

        return $this->redirectToRoute('app_admin_template', ['template' => $template->getId()]);
    }

    #[Route('/{template}/automations', name: 'app_admin_template_automations', requirements: ['template' => '\d+'], methods: ['GET'])]
    public function automations(#[MapEntity(id: 'template')] ProjectTemplate $template): Response
    {
        return $this->render('admin/template/automations.html.twig', ['template' => $template, 'rules' => $this->rules->findByTemplate($template)]);
    }

    #[Route('/{template}/automations/new', name: 'app_admin_template_automation_new', requirements: ['template' => '\d+'], methods: ['GET'])]
    public function newRule(#[MapEntity(id: 'template')] ProjectTemplate $template): Response
    {
        return $this->render('admin/template/automation.html.twig', ['template' => $template, 'rule' => null]);
    }

    #[Route('/{template}/automations/{rule}', name: 'app_admin_template_automation', requirements: ['template' => '\d+', 'rule' => '\d+'], methods: ['GET'])]
    public function rule(#[MapEntity(id: 'template')] ProjectTemplate $template, #[MapEntity(id: 'rule')] TemplateRule $rule): Response
    {
        $this->ofTemplate($rule->getTemplate(), $template);

        return $this->render('admin/template/automation.html.twig', ['template' => $template, 'rule' => $rule]);
    }

    /**
     * Whether the rule is switched on in the projects made from the template.
     */
    #[Route('/{template}/automations/{rule}/{action}', name: 'app_admin_template_automation_switch', requirements: ['template' => '\d+', 'rule' => '\d+', 'action' => 'activate|deactivate'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function switch(#[MapEntity(id: 'template')] ProjectTemplate $template, #[MapEntity(id: 'rule')] TemplateRule $rule, string $action): Response
    {
        $this->ofTemplate($rule->getTemplate(), $template);
        try {
            $this->commands->dispatch(new UpdateTemplateRule((int) $rule->getId(), active: 'activate' === $action));
            $this->addFlash('success', $this->translator->trans('activate' === $action ? 'automation.activated' : 'automation.deactivated'));
        } catch (AutomationException|TemplateException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }

        return $this->redirectToRoute('app_admin_template_automations', ['template' => $template->getId()]);
    }

    /**
     * The roles the template gives to teams on the projects made from it.
     */
    #[Route('/{template}/access', name: 'app_admin_template_access', requirements: ['template' => '\d+'], methods: ['GET'])]
    public function access(#[MapEntity(id: 'template')] ProjectTemplate $template): Response
    {
        return $this->render('admin/template/access.html.twig', ['template' => $template]);
    }

    #[Route('/{template}/settings', name: 'app_admin_template_settings', requirements: ['template' => '\d+'], methods: ['GET', 'POST'])]
    public function settings(#[MapEntity(id: 'template')] ProjectTemplate $template, Request $request): Response
    {
        $data = new TemplateData();
        $data->name = $template->getName();
        $data->description = $template->getDescription();
        $form = $this->createForm(TemplateType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new UpdateTemplate((int) $template->getId(), (string) $data->name, (string) $data->description));
                $this->addFlash('success', $this->translator->trans('admin.saved'));

                return $this->redirectToRoute('app_admin_template_settings', ['template' => $template->getId()]);
            } catch (TemplateException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/template/settings.html.twig', ['template' => $template, 'form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Deletes the template, confirmed in place on its settings: no
     * project loses anything.
     */
    #[Route('/{template}/delete', name: 'app_admin_template_delete', requirements: ['template' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function delete(#[MapEntity(id: 'template')] ProjectTemplate $template): Response
    {
        $name = $template->getName();
        $this->commands->dispatch(new DeleteTemplate((int) $template->getId()));
        $this->addFlash('success', $this->translator->trans('admin.template.deleted', ['%name%' => $name]));

        return $this->redirectToRoute('app_admin_templates');
    }

    /**
     * A tracker or a rule of another template is not found here.
     */
    private function ofTemplate(ProjectTemplate $owner, ProjectTemplate $template): void
    {
        if ($owner->getId() !== $template->getId()) {
            throw $this->createNotFoundException();
        }
    }
}
