<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Automation\AutomationException;
use App\Automation\MissingReferents;
use App\Automation\TeamsWithoutAccess;
use App\Entity\AutomationRule;
use App\Entity\Enum\RunOutcome;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Command\Automation\UpdateRule;
use App\Message\CommandBus;
use App\Message\Query\Automation\ListAutomationRuns;
use App\Message\QueryBus;
use App\Repository\AutomationRuleRepository;
use App\Repository\AutomationRunRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The automation rules of a project, for who configures it: the list of
 * the rules, each switched on or off in place, the editor of one (the
 * live component Automation:RuleForm does the writing) and the history
 * of their executions.
 */
#[Route('/{workspaceKey}/{projectKey}/settings/automations', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
#[IsGranted('project.configure', subject: 'project')]
final class AutomationController extends AbstractController
{
    public function __construct(
        private readonly AutomationRuleRepository $rules,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_project_settings_automations', methods: ['GET'])]
    public function rules(#[CurrentProject] Project $project, AutomationRunRepository $runs, MissingReferents $missingReferents, TeamsWithoutAccess $teamsWithoutAccess): Response
    {
        $rules = $this->rules->findByProject($project);

        return $this->render('project/settings/automations.html.twig', [
            'project' => $project,
            'rules' => $rules,
            'lastRuns' => $runs->findLastRuns($rules),
            'missingReferents' => $missingReferents->of($project, $rules),
            'teamsWithoutAccess' => $teamsWithoutAccess->of($project, $rules),
        ]);
    }

    #[Route('/runs', name: 'app_project_settings_automation_runs', methods: ['GET'])]
    public function runs(#[CurrentProject] Project $project, Request $request, QueryBus $queries): Response
    {
        $ruleId = $request->query->getInt('rule') ?: null;
        $outcome = RunOutcome::tryFrom($request->query->getString('outcome'));

        return $this->render('project/settings/automation_runs.html.twig', [
            'project' => $project,
            'rules' => $this->rules->findByProject($project),
            'ruleId' => $ruleId,
            'outcome' => $outcome,
            'runs' => $queries->ask(new ListAutomationRuns($project, $ruleId, $outcome, max(1, $request->query->getInt('page', 1)))),
        ]);
    }

    #[Route('/new', name: 'app_project_settings_automation_new', methods: ['GET'])]
    public function new(#[CurrentProject] Project $project): Response
    {
        return $this->render('project/settings/automation.html.twig', ['project' => $project, 'rule' => null]);
    }

    #[Route('/{rule}', name: 'app_project_settings_automation', requirements: ['rule' => '\d+'], methods: ['GET'])]
    public function edit(#[CurrentProject] Project $project, #[MapEntity(id: 'rule')] AutomationRule $rule): Response
    {
        return $this->render('project/settings/automation.html.twig', ['project' => $project, 'rule' => $this->ofProject($rule, $project)]);
    }

    #[Route('/{rule}/{action}', name: 'app_project_settings_automation_switch', requirements: ['rule' => '\d+', 'action' => 'activate|deactivate'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function switch(#[CurrentProject] Project $project, #[MapEntity(id: 'rule')] AutomationRule $rule, string $action, #[CurrentUser] User $actor, CommandBus $commands): Response
    {
        $this->ofProject($rule, $project);
        try {
            $commands->dispatch(new UpdateRule((int) $rule->getId(), active: 'activate' === $action, actorId: (int) $actor->getId()));
            $this->addFlash('success', $this->translator->trans('activate' === $action ? 'automation.activated' : 'automation.deactivated'));
        } catch (AutomationException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }

        return $this->redirectToRoute('app_project_settings_automations', ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()]);
    }

    /**
     * A rule of another project is not found here.
     */
    private function ofProject(AutomationRule $rule, Project $project): AutomationRule
    {
        if ($rule->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException();
        }

        return $rule;
    }
}
