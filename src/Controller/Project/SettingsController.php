<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Config\ConfigRegistry;
use App\Controller\OpensDialog;
use App\Entity\CodeRepository;
use App\Entity\Enum\Forge;
use App\Entity\Project;
use App\Entity\Tracker;
use App\Entity\User;
use App\Entity\Workspace;
use App\Forge\ForgeApiLocator;
use App\Form\Forge\CodeRepositoryData;
use App\Form\Forge\CodeRepositoryType;
use App\Form\Project\ProjectSettingsData;
use App\Form\Project\ProjectSettingsType;
use App\Form\Tracker\TrackerData;
use App\Form\Tracker\TrackerType;
use App\Message\Command\Forge\LinkRepository;
use App\Message\Command\Forge\UnlinkRepository;
use App\Message\Command\Forge\UpdateRepository;
use App\Message\Command\Project\UpdateProject;
use App\Message\Command\Tracker\CreateTracker;
use App\Message\Command\Tracker\DeleteTracker;
use App\Message\Command\Tracker\UpdateTracker;
use App\Message\CommandBus;
use App\MessageHandler\Command\Forge\CodeRepositoryException;
use App\MessageHandler\Command\Tracker\TrackerException;
use App\Repository\AutomationRuleRepository;
use App\Repository\CodeRepositoryRepository;
use App\Repository\IssueRepository;
use App\Repository\TrackerRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The configuration of a project, each subject under a permission of its
 * own: its trackers (the list, the dialog creating one, the settings of
 * one, where it is archived or deleted) under `tracker.manage`, its
 * general settings under `project.configure` and the repositories of the
 * forges linked to it (the list, the dialog linking one) under
 * `repository.manage`. A dialog has an address of its own, which renders
 * the list with it open. Archiving the project stays in the
 * administration.
 */
#[Route('/{workspaceKey}/{projectKey}/settings', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
#[IsGranted('view', subject: 'project')]
final class SettingsController extends AbstractController
{
    use OpensDialog;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly ConfigRegistry $config,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The trackers of the project: the page of the first one, where the
     * others are listed beside it; the bare list while there is none.
     */
    #[Route('', name: 'app_project_settings', methods: ['GET'])]
    #[IsGranted('tracker.manage', subject: 'project')]
    public function trackers(#[CurrentProject] Project $project, Request $request, TrackerRepository $trackers): Response
    {
        $first = $trackers->findByProject($project, includeArchived: true)[0] ?? null;
        if (null !== $first) {
            return $this->redirectToRoute('app_project_settings_tracker', self::route($project) + ['tracker' => $first->getId()]);
        }

        return $this->renderWithDialog($request, 'project/settings/trackers.html.twig', ['project' => $project, 'form' => null], static fn (): array => []);
    }

    /**
     * The list of the trackers with the dialog creating one; the new
     * tracker is then shown on its own page.
     */
    #[Route('/trackers/new', name: 'app_project_settings_tracker_new', methods: ['GET', 'POST'])]
    #[IsGranted('tracker.manage', subject: 'project')]
    public function newTracker(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request, FormFactoryInterface $forms): Response
    {
        $data = new TrackerData();
        // sent to the address of the dialog, not to the page it is loaded over; under a name of its own, since
        // that page may be the one of a tracker, whose form would otherwise share the ids of its fields
        $form = $forms->createNamed('new_tracker', TrackerType::class, $data, ['action' => $request->getRequestUri(), 'creation' => true, 'workflows' => $this->config->definition()->workflowLabels()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $tracker = $this->commands->dispatch(new CreateTracker((int) $actor->getId(), (int) $project->getId(), (string) $data->name, (string) $data->workflowId, $data->description, $data->private));
                \assert($tracker instanceof Tracker);
                $this->addFlash('success', $this->translator->trans('project.settings.tracker.created', ['%name%' => $tracker->getName()]));

                return $this->redirectToRoute('app_project_settings_tracker', self::route($project) + ['tracker' => $tracker->getId()]);
            } catch (TrackerException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderWithDialog($request, 'project/settings/trackers.html.twig', ['project' => $project, 'form' => $form], static fn (): array => [], $form);
    }

    #[Route('/trackers/{tracker}', name: 'app_project_settings_tracker', requirements: ['tracker' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('tracker.manage', subject: 'project')]
    public function tracker(#[CurrentProject] Project $project, #[MapEntity(id: 'tracker')] Tracker $tracker, #[CurrentUser] User $actor, Request $request, IssueRepository $issues, AutomationRuleRepository $rules): Response
    {
        if ($tracker->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException();
        }
        if (self::forDialog($request)) {
            // where the dialog creating a tracker leads: the frame is answered its empty self
            return $this->renderWithDialog($request, 'project/settings/tracker.html.twig', ['project' => $project], static fn (): array => []);
        }
        $data = new TrackerData();
        $data->name = $tracker->getName();
        $data->description = $tracker->getDescription();
        $data->private = $tracker->isPrivate();
        $form = $this->createForm(TrackerType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->commands->dispatch(new UpdateTracker((int) $actor->getId(), (int) $tracker->getId(), (string) $data->name, (string) $data->description, $data->private));
            $this->addFlash('success', $this->translator->trans('project.settings.saved'));

            return $this->redirectToRoute('app_project_settings_tracker', self::route($project) + ['tracker' => $tracker->getId()]);
        }

        $definition = $this->config->definition();

        return $this->render('project/settings/tracker.html.twig', [
            'project' => $project,
            'tracker' => $tracker,
            'workflow' => $definition->workflow($tracker->getWorkflowId()),
            'form' => $form,
            'issueCount' => $issues->count(['tracker' => $tracker]),
            'ruleCount' => $rules->count(['tracker' => $tracker]),
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Archives the tracker or brings it back, or deletes it when it holds
     * no issue: the buttons under its settings.
     */
    #[Route('/trackers/{tracker}/{action}', name: 'app_project_settings_tracker_action', requirements: ['tracker' => '\d+', 'action' => 'archive|unarchive|delete'], methods: ['POST'])]
    #[IsGranted('tracker.manage', subject: 'project')]
    #[IsCsrfTokenValid('submit')]
    public function trackerAction(#[CurrentProject] Project $project, #[MapEntity(id: 'tracker')] Tracker $tracker, string $action, #[CurrentUser] User $actor): Response
    {
        if ($tracker->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException();
        }
        $name = $tracker->getName();
        $id = (int) $tracker->getId();
        $deleted = false;
        try {
            $this->commands->dispatch(match ($action) {
                'delete' => new DeleteTracker((int) $actor->getId(), $id),
                default => new UpdateTracker((int) $actor->getId(), $id, archived: 'archive' === $action),
            });
            $this->addFlash('success', $this->translator->trans('project.settings.tracker.done.'.$action, ['%name%' => $name]));
            $deleted = 'delete' === $action;
        } catch (TrackerException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }
        if ($deleted) {
            return $this->redirectToRoute('app_project_settings', self::route($project));
        }

        return $this->redirectToRoute('app_project_settings_tracker', self::route($project) + ['tracker' => $id]);
    }

    #[Route('/general', name: 'app_project_settings_general', methods: ['GET', 'POST'])]
    #[IsGranted('project.configure', subject: 'project')]
    public function general(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request): Response
    {
        $data = new ProjectSettingsData();
        $data->name = $project->getName();
        $data->description = $project->getDescription();
        $data->budgetUnit = $project->getBudgetUnit();
        $form = $this->createForm(ProjectSettingsType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->commands->dispatch(new UpdateProject((int) $project->getId(), (string) $data->name, (string) $data->description, actorId: (int) $actor->getId(), budgetUnit: $data->budgetUnit, withoutBudgets: null === $data->budgetUnit));
            $this->addFlash('success', $this->translator->trans('project.settings.saved'));

            return $this->redirectToRoute('app_project_settings_general', self::route($project));
        }

        return $this->render('project/settings/general.html.twig', ['project' => $project, 'form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * The repositories linked to the project, each installed on its forge
     * or waiting to be, by the API or by hand with the URL and the secret
     * shown; none of it where a hook of the instance delivers them all.
     */
    #[Route('/repositories', name: 'app_project_settings_repositories', methods: ['GET'])]
    #[IsGranted('repository.manage', subject: 'project')]
    public function repositories(#[CurrentProject] Project $project, Request $request, CodeRepositoryRepository $repositories, ForgeApiLocator $apis): Response
    {
        return $this->renderRepositories($request, $project, $repositories, $apis, null);
    }

    /**
     * The list of the repositories with the dialog linking one more,
     * installed at once when the instance token allows it, and never where
     * a hook of the instance delivers every repository.
     */
    #[Route('/repositories/new', name: 'app_project_settings_repository_new', methods: ['GET', 'POST'])]
    #[IsGranted('repository.manage', subject: 'project')]
    public function newRepository(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request, CodeRepositoryRepository $repositories, ForgeApiLocator $apis): Response
    {
        $data = new CodeRepositoryData();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(CodeRepositoryType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $forge = $data->forge ?? Forge::GitLab;
            try {
                $repository = $this->commands->dispatch(new LinkRepository((int) $project->getId(), $forge, (string) $data->path, (int) $actor->getId()));
                \assert($repository instanceof CodeRepository);
                if ($apis->for($forge)->hasInstanceHook()) {
                    $this->addFlash('success', $this->translator->trans('project.settings.repository.linked_instance', ['%path%' => $repository->getPath()]));
                } elseif ($apis->for($forge)->isConfigured()) {
                    $this->commands->dispatch(new UpdateRepository((int) $repository->getId(), installed: true, actorId: (int) $actor->getId()));
                    $this->addFlash('success', $this->translator->trans('project.settings.repository.linked_installed', ['%path%' => $repository->getPath()]));
                } else {
                    $this->addFlash('success', $this->translator->trans('project.settings.repository.linked_manual', ['%path%' => $repository->getPath()]));
                }

                return $this->redirectToRoute('app_project_settings_repositories', self::route($project));
            } catch (CodeRepositoryException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderRepositories($request, $project, $repositories, $apis, $form);
    }

    #[Route('/repositories/{repository}/{action}', name: 'app_project_settings_repository', requirements: ['repository' => '\d+', 'action' => 'install|uninstall|secret|unlink'], methods: ['POST'])]
    #[IsGranted('repository.manage', subject: 'project')]
    #[IsCsrfTokenValid('submit')]
    public function repository(#[CurrentProject] Project $project, #[MapEntity(id: 'repository')] CodeRepository $repository, string $action, #[CurrentUser] User $actor): Response
    {
        if ($repository->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException();
        }
        $id = (int) $repository->getId();
        $actorId = (int) $actor->getId();
        $path = $repository->getPath();
        try {
            $this->commands->dispatch(match ($action) {
                'install' => new UpdateRepository($id, installed: true, actorId: $actorId),
                'uninstall' => new UpdateRepository($id, installed: false, actorId: $actorId),
                'secret' => new UpdateRepository($id, regenerateSecret: true, actorId: $actorId),
                default => new UnlinkRepository($id, $actorId),
            });
            $this->addFlash('success', $this->translator->trans('project.settings.repository.done.'.$action, ['%path%' => $path]));
        } catch (CodeRepositoryException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }

        return $this->redirectToRoute('app_project_settings_repositories', self::route($project));
    }

    /**
     * @param FormInterface<CodeRepositoryData>|null $form the dialog linking a repository, when it is open
     */
    private function renderRepositories(Request $request, Project $project, CodeRepositoryRepository $repositories, ForgeApiLocator $apis, ?FormInterface $form): Response
    {
        $api = $apis->for(Forge::GitLab);

        return $this->renderWithDialog($request, 'project/settings/repositories.html.twig', [
            'project' => $project,
            'canInstall' => $api->isConfigured(),
            'instanceHook' => $api->hasInstanceHook(),
            'form' => $form,
        ], static fn (): array => [
            'repositories' => $repositories->findByProject($project),
            'webhookUrl' => $api->deliveryUrl(),
        ], $form);
    }

    /**
     * @return array{workspaceKey: string, projectKey: string}
     */
    private static function route(Project $project): array
    {
        return ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()];
    }
}
