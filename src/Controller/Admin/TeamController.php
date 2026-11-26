<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Team;
use App\Form\Team\TeamData;
use App\Form\Team\TeamType;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\DeleteTeam;
use App\Message\Command\Team\UpdateTeam;
use App\Message\CommandBus;
use App\MessageHandler\Command\Team\TeamException;
use App\Repository\TeamMemberRepository;
use App\Repository\TeamRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The teams: people who answer as one, internal or external. A team page
 * has a route per tab: its members, the roles it holds, its settings
 * (name, deletion).
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/teams')]
final class TeamController extends AbstractController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_admin_teams', methods: ['GET'])]
    public function list(TeamRepository $teams, TeamMemberRepository $members): Response
    {
        return $this->render('admin/teams.html.twig', [
            'teams' => $teams->findAllByName(),
            'members' => $members->countByTeam(),
        ]);
    }

    #[Route('/new', name: 'app_admin_team_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new TeamData();
        $form = $this->createForm(TeamType::class, $data, ['creation' => true]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $team = $this->commands->dispatch(new CreateTeam((string) $data->name, TeamData::INTERNAL === $data->nature));
                \assert($team instanceof Team);
                $this->addFlash('success', $this->translator->trans('admin.team.created', ['%name%' => $team->getName()]));

                return $this->redirectToRoute('app_admin_team', ['id' => $team->getId()]);
            } catch (TeamException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/team_new.html.twig', ['form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}', name: 'app_admin_team', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function members(#[MapEntity] Team $team): Response
    {
        return $this->render('admin/team/members.html.twig', ['team' => $team]);
    }

    #[Route('/{id}/access', name: 'app_admin_team_access', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function access(#[MapEntity] Team $team): Response
    {
        return $this->render('admin/team/access.html.twig', ['team' => $team]);
    }

    #[Route('/{id}/settings', name: 'app_admin_team_settings', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function settings(#[MapEntity] Team $team, Request $request): Response
    {
        $data = new TeamData();
        $data->name = $team->getName();
        $form = $this->createForm(TeamType::class, $data);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new UpdateTeam((int) $team->getId(), (string) $data->name));
                $this->addFlash('success', $this->translator->trans('admin.saved'));

                return $this->redirectToRoute('app_admin_team_settings', ['id' => $team->getId()]);
            } catch (TeamException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->render('admin/team/settings.html.twig', ['team' => $team, 'form' => $form], new Response(null, $form->isSubmitted() ? 422 : 200));
    }

    #[Route('/{id}/delete', name: 'app_admin_team_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function delete(#[MapEntity] Team $team): RedirectResponse
    {
        $id = (int) $team->getId();
        $name = $team->getName();
        try {
            $this->commands->dispatch(new DeleteTeam($id));
            $this->addFlash('success', $this->translator->trans('admin.team.deleted', ['%name%' => $name]));

            return $this->redirectToRoute('app_admin_teams');
        } catch (TeamException $e) {
            $this->addFlash('error', $e->trans($this->translator));
        }

        return $this->redirectToRoute('app_admin_team_settings', ['id' => $id]);
    }
}
