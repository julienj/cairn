<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Project;
use App\Entity\User;
use App\Entity\WidgetSite;
use App\Entity\Workspace;
use App\Message\Command\Widget\LinkWidget;
use App\Message\CommandBus;
use App\Repository\WidgetSiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The window the widget opens for someone to link their account to it on
 * one site, in two addresses.
 *
 * `connect` is the way in, open to anybody: it only takes a request the
 * frame of the widget made (which only the sites of the project can
 * show), notes in the session which site asks under which challenge,
 * and goes on to `link` for that challenge: two sites of a project may
 * be linked side by side, each in its window. Another site can therefore not
 * send someone here to link their account to a widget of its own making.
 *
 * `link` is an ordinary page: whoever is not signed in goes through the
 * sign-in page first, password or single sign-on, and comes back. It
 * shows who links what, and agreeing leaves the agreement the widget
 * comes and takes (LinkWidget).
 */
#[Route('/{workspaceKey}/{projectKey}/widget', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
final class WidgetLinkController extends AbstractController
{
    /** The session key of what `connect` noted, per project and per challenge: two sites of a project may be linked side by side. */
    private const string PENDING = 'widget_link.%d.%s';
    private const string CHALLENGE = '[0-9a-f]{64}';

    public function __construct(
        private readonly WidgetSiteRepository $sites,
        private readonly CommandBus $commands,
    ) {
    }

    #[Route('/connect', name: 'app_widget_connect', methods: ['GET'])]
    public function connect(#[CurrentProject] Project $project, Request $request): RedirectResponse
    {
        $origin = WidgetSite::normalise($request->query->getString('site'));
        $challenge = $request->query->getString('challenge');
        if ('same-origin' !== $request->headers->get('Sec-Fetch-Site')
            || !$this->fromTheFrame($project, $request)
            || null === $origin || null === $this->sites->findOneByOrigin($project, $origin)
            || 1 !== preg_match('/^'.self::CHALLENGE.'$/', $challenge)
        ) {
            throw new NotFoundHttpException('Linking an account starts from the widget of a site of the project.');
        }
        $request->getSession()->set(\sprintf(self::PENDING, $project->getId(), $challenge), $origin);

        return $this->redirectToRoute('app_widget_link', ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey(), 'challenge' => $challenge]);
    }

    #[Route('/link/{challenge}', name: 'app_widget_link', requirements: ['challenge' => self::CHALLENGE], methods: ['GET'])]
    #[IsGranted('issue.create', subject: 'project')]
    public function link(#[CurrentProject] Project $project, string $challenge, Request $request): Response
    {
        return $this->render('project/widget_link.html.twig', [
            'project' => $project,
            'challenge' => $challenge,
            'origin' => $this->pending($project, $challenge, $request),
            'linked' => false,
        ]);
    }

    #[Route('/link/{challenge}', name: 'app_widget_link_confirm', requirements: ['challenge' => self::CHALLENGE], methods: ['POST'])]
    #[IsGranted('issue.create', subject: 'project')]
    #[IsCsrfTokenValid('submit')]
    public function confirm(#[CurrentProject] Project $project, string $challenge, #[CurrentUser] User $actor, Request $request): Response
    {
        $origin = $this->pending($project, $challenge, $request);
        if (null !== $origin) {
            $request->getSession()->remove(\sprintf(self::PENDING, $project->getId(), $challenge));
            $this->commands->dispatch(new LinkWidget((int) $actor->getId(), (int) $project->getId(), $origin, $challenge));
        }

        return $this->render('project/widget_link.html.twig', [
            'project' => $project,
            'challenge' => $challenge,
            'origin' => $origin,
            'linked' => null !== $origin,
        ]);
    }

    /**
     * Whether the request was made by the frame of the widget of the
     * project: a link someone wrote in a page of Cairn is not.
     */
    private function fromTheFrame(Project $project, Request $request): bool
    {
        $frame = $this->generateUrl('app_widget', ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()], UrlGeneratorInterface::ABSOLUTE_URL);

        return $frame === strtok((string) $request->headers->get('Referer'), '?');
    }

    /**
     * The site `connect` noted for the project under that challenge, if it is still one of its own.
     */
    private function pending(Project $project, string $challenge, Request $request): ?string
    {
        $origin = $request->getSession()->get(\sprintf(self::PENDING, $project->getId(), $challenge));

        return \is_string($origin) && null !== $this->sites->findOneByOrigin($project, $origin) ? $origin : null;
    }
}
