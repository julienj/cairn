<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\User\TwoFactorCodeData;
use App\Form\User\TwoFactorCodeType;
use App\Message\Command\User\DisableTwoFactor;
use App\Message\CommandBus;
use App\MessageHandler\Command\User\UserException;
use App\Security\SingleSignOn;
use App\Security\TwoFactorCondition;
use App\Security\TwoFactorPolicy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
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
 * The second factor of the signed-in user, kept up from the account: where it stands, a way to the page that sets it
 * up (Security/TwoFactorEnableController) and turning it off unless the instance holds the account to it. The
 * codes typed here have a limit of their own, apart from the sign-in's, forgotten at the first that is right.
 */
final class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
        private readonly FormFactoryInterface $forms,
        private readonly TwoFactorPolicy $policy,
        #[Target('two_factor.limiter')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/account/two-factor', name: 'app_account_two_factor', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, SingleSignOn $sso): Response
    {
        if (!$this->policy->isAvailableTo($user)) {
            return $this->render('account/two_factor.html.twig', ['state' => 'sso', 'ssoName' => $sso->name()]);
        }
        if ($user->hasTotp()) {
            return $this->manage($user, $this->disableForm());
        }

        return $this->render('account/two_factor.html.twig', ['state' => 'off']);
    }

    #[Route('/account/two-factor/disable', name: 'app_account_two_factor_disable', methods: ['POST'])]
    public function disable(#[CurrentUser] User $user, Request $request, Security $security): Response
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
                // Taking the secret off ended every other session of the account (isEqualTo); this one goes on.
                $request->attributes->set(TwoFactorCondition::SKIP, true);
                $security->login($user, 'form_login', 'main');
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
