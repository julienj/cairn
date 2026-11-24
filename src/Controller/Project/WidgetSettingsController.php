<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Controller\OpensDialog;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\WidgetSite;
use App\Entity\Workspace;
use App\Form\Widget\WidgetSiteData;
use App\Form\Widget\WidgetSiteType;
use App\Message\Command\Widget\AddWidgetSite;
use App\Message\Command\Widget\RemoveWidgetSite;
use App\Message\CommandBus;
use App\MessageHandler\Command\Widget\WidgetException;
use App\Repository\WidgetSiteRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The widget of a project, for its manager: the sites it is shown on and
 * the line of script to put on them, a dialog that declares a site, which
 * has an address of its own and renders the list with it open. The widget
 * exists as soon as a site does.
 */
#[Route('/{workspaceKey}/{projectKey}/settings/widget', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
#[IsGranted('project.configure', subject: 'project')]
final class WidgetSettingsController extends AbstractController
{
    use OpensDialog;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly WidgetSiteRepository $sites,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_project_settings_widget', methods: ['GET'])]
    public function list(#[CurrentProject] Project $project, Request $request): Response
    {
        return $this->renderList($request, $project, null);
    }

    #[Route('/new', name: 'app_project_settings_widget_site_new', methods: ['GET', 'POST'])]
    public function new(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request): Response
    {
        $data = new WidgetSiteData();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(WidgetSiteType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $site = $this->commands->dispatch(new AddWidgetSite((int) $actor->getId(), (int) $project->getId(), (string) $data->address));
                \assert($site instanceof WidgetSite);
                $this->addFlash('success', $this->translator->trans('widget.site.added', ['%origin%' => $site->getOrigin()]));

                return $this->redirectToRoute('app_project_settings_widget', self::route($project));
            } catch (WidgetException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderList($request, $project, $form);
    }

    /**
     * Removes the site: whoever linked their account there is signed out of the widget.
     */
    #[Route('/{site}/delete', name: 'app_project_settings_widget_site_delete', requirements: ['site' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function delete(#[CurrentProject] Project $project, #[MapEntity(id: 'site')] WidgetSite $site, #[CurrentUser] User $actor): RedirectResponse
    {
        if ($site->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException(\sprintf('Site %d is not a site of %s.', $site->getId(), $project->getIssuePrefix()));
        }
        $origin = $site->getOrigin();
        $this->commands->dispatch(new RemoveWidgetSite((int) $actor->getId(), (int) $site->getId()));
        $this->addFlash('success', $this->translator->trans('widget.site.removed', ['%origin%' => $origin]));

        return $this->redirectToRoute('app_project_settings_widget', self::route($project));
    }

    /**
     * @param FormInterface<WidgetSiteData>|null $form the dialog, when it is open
     */
    private function renderList(Request $request, Project $project, ?FormInterface $form): Response
    {
        return $this->renderWithDialog($request, 'project/settings/widget.html.twig', [
            'project' => $project,
            'form' => $form,
        ], fn (): array => [
            'sites' => $this->sites->findByProject($project),
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
