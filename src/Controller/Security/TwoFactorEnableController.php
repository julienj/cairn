<?php

declare(strict_types=1);

namespace App\Controller\Security;

use App\Entity\User;
use App\Form\User\TwoFactorCodeData;
use App\Form\User\TwoFactorCodeType;
use App\Message\Command\User\EnableTwoFactor;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Security\PendingTotp;
use App\Security\TwoFactorPolicy;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the second factor is set up, in the layout of the sign-in: the QR code of a secret kept in the session
 * until a code of the app proves it works. Whoever the instance holds to it is sent here by
 * `Security/TwoFactorEnrolment` and comes back to the page they asked for; the others reach it from their account.
 * No limit on the tries: the secret is on the screen, there is nothing to guess.
 */
final class TwoFactorEnableController extends AbstractController
{
    use TargetPathTrait;

    /** The secret being scanned, kept from one request to the other until it is confirmed. */
    private const string SECRET = 'two_factor_secret';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $forms,
        private readonly TotpAuthenticatorInterface $totp,
        private readonly TwoFactorPolicy $policy,
    ) {
    }

    #[Route('/security/2fa/enable', name: 'app_two_factor_enable', methods: ['GET', 'POST'])]
    public function enable(#[CurrentUser] User $user, Request $request): Response
    {
        if (!$this->policy->isAvailableTo($user) || $user->hasTotp()) {
            return $this->redirectToRoute('app_account_two_factor');
        }

        $session = $request->getSession();
        $secret = $session->get(self::SECRET);
        if (!\is_string($secret)) {
            $session->set(self::SECRET, $secret = $this->totp->generateSecret());
        }

        $data = new TwoFactorCodeData();
        $form = $this->forms->createNamed('enable', TwoFactorCodeType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new EnableTwoFactor((int) $user->getId(), $secret, (string) $data->code));
                $session->remove(self::SECRET);
                $this->addFlash('success', $this->translator->trans('account.two_factor.enabled'));

                $target = $this->getTargetPath($session, 'main');
                $this->removeTargetPath($session, 'main');

                return null !== $target ? $this->redirect($target) : $this->redirectToRoute('app_account_two_factor');
            } catch (UserException $e) {
                $form->get('code')->addError(new FormError($e->trans($this->translator)));
            }
        }

        $qr = (new Builder(writer: new SvgWriter(), data: $this->totp->getQRContent(new PendingTotp($secret, $user->getUserIdentifier())), size: 200, margin: 8))->build();

        return $this->render('security/two_factor_enable.html.twig', [
            'form' => $form,
            'qrCode' => $qr->getDataUri(),
            'secret' => implode(' ', str_split($secret, 4)),
            'required' => $this->policy->requires($user),
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }
}
