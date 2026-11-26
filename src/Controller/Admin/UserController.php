<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\User\UserData;
use App\Form\User\UserFilters;
use App\Form\User\UserType;
use App\Message\Command\User\CreateUser;
use App\Message\Command\User\DeleteUser;
use App\Message\Command\User\InviteUser;
use App\Message\Command\User\ResetTwoFactor;
use App\Message\Command\User\UpdateUser;
use App\Message\CommandBus;
use App\Message\Query\User\SearchUsers;
use App\Message\QueryBus;
use App\MessageHandler\Command\User\UserException;
use App\Repository\UserRepository;
use App\Security\SingleSignOn;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The accounts. An administrator creates one without a password and the
 * person is invited by mail to choose theirs; the invitation can be sent
 * again while the account has none. The page of an account is one form,
 * turned into one UpdateUser.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/users')]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
        private readonly UserRepository $users,
        private readonly TranslatorInterface $translator,
        private readonly SingleSignOn $sso,
    ) {
    }

    #[Route('', name: 'app_admin_users', methods: ['GET'])]
    public function list(
        #[MapQueryString]
        UserFilters $filters = new UserFilters(),
    ): Response {
        return $this->render('admin/users.html.twig', [
            'filters' => $filters,
            'users' => $this->queries->ask(new SearchUsers($filters->q, $filters->type, $filters->state, $filters->page)),
        ]);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new UserData();
        $form = $this->createForm(UserType::class, $data, ['creation' => true, 'sso' => $this->sso->enabled() ? $this->sso->name() : null]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $user = $this->commands->dispatch(new CreateUser((string) $data->email, (string) $data->firstName, (string) $data->lastName, $data->isInternal(), $data->isAdmin(), $data->company, authMethod: $data->authMethod));
                \assert($user instanceof User);
                if ($user->usesSso()) {
                    // Nothing to choose, so nothing is mailed: the person is told by whoever created the account.
                    $this->addFlash('success', $this->translator->trans('admin.user.created_sso', ['%name%' => $user->getFullName(), '%provider%' => $this->sso->name()]));
                } else {
                    $this->commands->dispatch(new InviteUser((int) $user->getId()));
                    $this->addFlash('success', $this->translator->trans('admin.user.created', ['%name%' => $user->getFullName(), '%email%' => $user->getEmail()]));
                }

                return $this->redirectToRoute('app_admin_user', ['id' => $user->getId()]);
            } catch (UserException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/user_new.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}', name: 'app_admin_user', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(#[MapEntity] User $user, Request $request): Response
    {
        $data = UserData::fromUser($user);
        // An account already told to sign in through the provider keeps the choice on its page, to be taken back to a password, even once the instance has no provider.
        $form = $this->createForm(UserType::class, $data, ['sso' => $this->sso->enabled() || $user->usesSso() ? $this->sso->name() : null]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new UpdateUser(
                    userId: (int) $user->getId(),
                    email: $data->email,
                    firstName: $data->firstName,
                    lastName: $data->lastName,
                    company: (string) $data->company,
                    internal: $data->isInternal(),
                    admin: $data->isAdmin(),
                    active: $data->active,
                    authMethod: $form->has('authMethod') ? $data->authMethod : null,
                ));
                $this->addFlash('success', $this->translator->trans('admin.saved'));

                return $this->redirectToRoute('app_admin_user', ['id' => $user->getId()]);
            } catch (UserException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/user.html.twig', ['user' => $user, 'form' => $form, 'hasHistory' => $this->users->hasHistory($user), 'ssoName' => $this->sso->name()], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    /**
     * Sends the invitation again, with a fresh link that replaces the
     * previous one.
     */
    #[Route('/{id}/invite', name: 'app_admin_user_invite', requirements: ['id' => '\\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function invite(#[MapEntity] User $user): RedirectResponse
    {
        try {
            $this->commands->dispatch(new InviteUser((int) $user->getId()));
            $this->addFlash('success', $this->translator->trans('admin.user.invitation_sent', ['%email%' => $user->getEmail()]));
        } catch (UserException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $user->getId()]);
    }

    /**
     * Takes the second factor off an account whose person lost their phone.
     */
    #[Route('/{id}/two-factor/reset', name: 'app_admin_user_two_factor_reset', requirements: ['id' => '\\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function resetTwoFactor(#[MapEntity] User $user): RedirectResponse
    {
        try {
            $this->commands->dispatch(new ResetTwoFactor((int) $user->getId()));
            $this->addFlash('success', $this->translator->trans('admin.user.two_factor.reset_done', ['%name%' => $user->getFullName()]));
        } catch (UserException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $user->getId()]);
    }

    /**
     * Deletes an account that left no trace; nobody deletes their own.
     */
    #[Route('/{id}/delete', name: 'app_admin_user_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function delete(#[MapEntity] User $user, #[CurrentUser] User $viewer): RedirectResponse
    {
        if ($user->getId() === $viewer->getId()) {
            throw $this->createAccessDeniedException('Nobody deletes their own account.');
        }
        $id = (int) $user->getId();
        $name = $user->getFullName();
        try {
            $this->commands->dispatch(new DeleteUser($id));
            $this->addFlash('success', $this->translator->trans('admin.user.deleted', ['%name%' => $name]));

            return $this->redirectToRoute('app_admin_users');
        } catch (UserException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }

        return $this->redirectToRoute('app_admin_user', ['id' => $id]);
    }
}
