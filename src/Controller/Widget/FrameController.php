<?php

declare(strict_types=1);

namespace App\Controller\Widget;

use App\Controller\Project\CurrentProject;
use App\Entity\Project;
use App\Entity\WidgetSite;
use App\Entity\Workspace;
use App\Message\Command\Widget\RedeemWidgetLink;
use App\Message\CommandBus;
use App\Repository\WidgetSiteRepository;
use App\Widget\FrameHeaders;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the widget of a project answers before anybody is known: whether
 * a site is one of its own (what the script of the site asks before it
 * shows its button), the frame the site puts in its panel, and the
 * exchange that gives that frame its token. Each is asked in the name of
 * a site and answers that site only: for any other, as for a project
 * without a widget or one that does not exist, everything here is a 404,
 * so that nobody learns from outside which projects there are nor where
 * they are reviewed.
 */
#[Route('/widget/{workspaceKey}/{projectKey}', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
final class FrameController extends AbstractController
{
    public function __construct(
        private readonly WidgetSiteRepository $sites,
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * The frame, for the site named in the address, which alone may show it (Widget/FrameHeaders).
     */
    #[Route('', name: 'app_widget', methods: ['GET'])]
    public function frame(#[CurrentProject] Project $project, Request $request): Response
    {
        $site = $this->site($project, $request->query->getString('site'));
        $request->attributes->set(FrameHeaders::ANCESTOR, $site->getOrigin());

        return $this->render('widget/frame.html.twig', ['project' => $project, 'site' => $site]);
    }

    /**
     * Tells the script of a site that the project knows it, with what its
     * button says: answered to the origin that asks and readable by it
     * alone.
     */
    #[Route('/site', name: 'app_widget_site', methods: ['GET'])]
    public function known(#[CurrentProject] Project $project, Request $request): JsonResponse
    {
        $site = $this->site($project, (string) $request->headers->get('Origin'));

        $response = new JsonResponse(['label' => $this->translator->trans('widget.title')]);
        $response->headers->set('Access-Control-Allow-Origin', $site->getOrigin());
        $response->setVary('Origin');

        return $response;
    }

    /**
     * Without a secret, makes one for the frame, with the challenge it
     * opens the linking window with: a frame of a site served over plain
     * HTTP cannot hash by itself. With the secret, gives the token once
     * the account was linked under its challenge, and nothing (204) until
     * then.
     */
    #[Route('/token', name: 'app_widget_token', methods: ['POST'])]
    public function token(#[CurrentProject] Project $project, Request $request): Response
    {
        $body = json_decode($request->getContent(), true);
        $body = \is_array($body) ? $body : [];
        $this->site($project, \is_string($body['site'] ?? null) ? $body['site'] : '');

        $verifier = $body['verifier'] ?? null;
        if (null === $verifier) {
            $verifier = bin2hex(random_bytes(32));

            return self::secret(['verifier' => $verifier, 'challenge' => hash('sha256', $verifier)]);
        }
        $token = \is_string($verifier) ? $this->commands->dispatch(new RedeemWidgetLink((int) $project->getId(), $verifier)) : null;

        return null === $token ? new Response(null, Response::HTTP_NO_CONTENT) : self::secret(['token' => $token]);
    }

    /**
     * The site of the project at that address, a 404 when it has none there.
     */
    private function site(Project $project, string $address): WidgetSite
    {
        $origin = WidgetSite::normalise($address);
        $site = $project->isReadOnly() || null === $origin ? null : $this->sites->findOneByOrigin($project, $origin);

        return $site ?? throw new NotFoundHttpException(\sprintf('%s has no widget for this site.', $project->getIssuePrefix()));
    }

    /**
     * @param array<string, string> $data
     */
    private static function secret(array $data): JsonResponse
    {
        $response = new JsonResponse($data);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
