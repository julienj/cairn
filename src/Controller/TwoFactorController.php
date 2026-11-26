<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\User\TwoFactorCodeData;
use App\Form\User\TwoFactorCodeType;
use App\Message\Command\User\DisableTwoFactor;
use App\Message\Command\User\EnableTwoFactor;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Security\PendingTotp;
use App\Security\SingleSignOn;
use App\Security\TwoFactorPolicy;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The second factor of the signed-in user, one page: set up (the QR code of a secret kept in the session until a
 * code of the app proves it works), then kept up (turning it off unless the instance holds the account to it).
 * Every code typed here counts against the limit of the sign-in, and is forgotten at the first that is right.
 */
final class TwoFactorController extends AbstractController
{
    /** The secret being scanned, kept from one request to the other until it is confirmed. */
    private const string SECRET = 'two_factor_secret';

    public function __construct(
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $forms,
        private readonly TotpAuthenticatorInterface $totp,
        private readonly TwoFactorPolicy $policy,
        #[Target('two_factor.limiter')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/account/two-factor', name: 'app_account_two_factor', methods: ['GET', 'POST'])]
    public function index(#[CurrentUser] User $user, Request $request, SingleSignOn $sso): Response
    {
        if (!$this->policy->isAvailableTo($user)) {
            return $this->render('account/two_factor.html.twig', ['state' => 'sso', 'ssoName' => $sso->name()]);
        }
        if ($user->hasTotp()) {
            return $this->manage($user, $this->disableForm());
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
            if (!$this->attempt($user)) {
                return $this->redirectToRoute('app_account_two_factor');
            }

            try {
                $this->commands->dispatch(new EnableTwoFactor((int) $user->getId(), $secret, (string) $data->code));
                $this->succeeded($user);
                $session->remove(self::SECRET);
                $this->addFlash('success', $this->translator->trans('account.two_factor.enabled'));

                return $this->redirectToRoute('app_account_two_factor');
            } catch (UserException $e) {
                $form->get('code')->addError(new FormError($e->trans($this->translator)));
            }
        }

        $qr = (new Builder(writer: new SvgWriter(), data: $this->totp->getQRContent(new PendingTotp($secret, $user->getUserIdentifier())), size: 200, margin: 8))->build();

        return $this->render('account/two_factor.html.twig', [
            'state' => 'setup',
            'form' => $form,
            'qrCode' => $qr->getDataUri(),
            'secret' => implode(' ', str_split($secret, 4)),
            'required' => $this->policy->requires($user),
        ], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/account/two-factor/disable', name: 'app_account_two_factor_disable', methods: ['POST'])]
    public function disable(#[CurrentUser] User $user, Request $request): Response
    {
        if (!$user->hasTotp()) {
            return $this->redirectToRoute('app_account_two_factor');
        }

        $data = new TwoFactorCodeData();
        $form = $this->disableForm($data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if (!$this->attempt($user)) {
                return $this->redirectToRoute('app_account_two_factor');
            }

            try {
                $this->commands->dispatch(new DisableTwoFactor((int) $user->getId(), (string) $data->code));
                $this->succeeded($user);
                $this->addFlash('success', $this->translator->trans('account.two_factor.disabled'));

                return $this->redirectToRoute('app_account_two_factor');
            } catch (UserException $e) {
                $form->get('code')->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->manage($user, $form, 422);
    }

    /**
     * @param FormInterface<TwoFactorCodeData> $disable
     */
    private function manage(User $user, FormInterface $disable, int $status = 200): Response
    {
        return $this->render('account/two_factor.html.twig', [
            'state' => 'enabled',
            'user' => $user,
            'disableForm' => $disable,
            'required' => $this->policy->requires($user),
        ], new Response(null, $status));
    }

    /**
     * @return FormInterface<TwoFactorCodeData>
     */
    private function disableForm(?TwoFactorCodeData $data = null): FormInterface
    {
        return $this->forms->createNamed('disable', TwoFactorCodeType::class, $data ?? new TwoFactorCodeData(), [
            'action' => $this->generateUrl('app_account_two_factor_disable'),
            'code_label' => 'account.two_factor.confirm_code',
        ]);
    }

    /**
     * Every submission counts, right or wrong, before the form says anything about the code. The key is the
     * account's own, apart from the sign-in's: one cannot lock the other.
     */
    private function attempt(User $user): bool
    {
        if ($this->limiter->create('manage:'.$user->getId())->consume()->isAccepted()) {
            return true;
        }

        $this->addFlash('error', $this->translator->trans('account.two_factor.too_many_attempts'));

        return false;
    }

    private function succeeded(User $user): void
    {
        $this->limiter->create('manage:'.$user->getId())->reset();
    }
}
