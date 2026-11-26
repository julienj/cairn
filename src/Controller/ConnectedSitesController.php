<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\WidgetToken;
use App\Message\Command\Widget\UnlinkWidget;
use App\Message\CommandBus;
use App\MessageHandler\Command\Widget\WidgetException;
use App\Repository\WidgetTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The sites the signed-in user linked their account on, to report
 * problems from them through the widget: one line per browser that is
 * still linked, and the way to take the account back from it.
 */
#[Route('/account/sites')]
final class ConnectedSitesController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_account_sites', methods: ['GET'])]
    public function list(#[CurrentUser] User $user, WidgetTokenRepository $tokens, ClockInterface $clock): Response
    {
        return $this->render('account/sites.html.twig', ['tokens' => $tokens->findUsableByUser($user, $clock->now())]);
    }

    #[Route('/{token}/disconnect', name: 'app_account_site_disconnect', requirements: ['token' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function disconnect(#[CurrentUser] User $user, #[MapEntity(id: 'token')] WidgetToken $token): Response
    {
        $origin = $token->getSite()->getOrigin();
        try {
            $this->commands->dispatch(new UnlinkWidget((int) $user->getId(), (int) $token->getId()));
            $this->addFlash('success', $this->translator->trans('connected_site.disconnected', ['%site%' => (string) preg_replace('~^https?://~', '', $origin)]));
        } catch (WidgetException) {
            throw $this->createNotFoundException();
        }

        return $this->redirectToRoute('app_account_sites');
    }
}
