<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\TeamMemberRepository;
use App\Twig\Components\IssueList\ListScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The issues across every project the signed-in user sees: all of them,
 * whoever they are assigned to, those assigned to them, those assigned
 * to their teams, those they created. One route per scope; the address
 * says how the list is arranged, and ShowsIssueList answers.
 */
#[Route('/issues')]
final class MyIssuesController extends AbstractController
{
    use ShowsIssueList;

    private const array DIMENSIONS = ['place', 'project', 'criticality'];

    #[Route('/all', name: 'app_my_issues_all', methods: ['GET', 'PUT'])]
    public function all(Request $request, #[CurrentUser] User $viewer, TeamMemberRepository $teamMembers): Response
    {
        $scope = new ListScope($request->getBaseUrl().$request->getPathInfo(), [...self::DIMENSIONS, 'assignee'], showProject: true);

        return $this->issueList($request, $viewer, $scope, 'my_issues.html.twig', self::tabs('all', $viewer, $teamMembers));
    }

    #[Route('/assigned', name: 'app_my_issues_assigned', methods: ['GET', 'PUT'])]
    public function assigned(Request $request, #[CurrentUser] User $viewer, TeamMemberRepository $teamMembers): Response
    {
        $scope = new ListScope($request->getBaseUrl().$request->getPathInfo(), self::DIMENSIONS, assigneeId: $viewer->getId(), showProject: true);

        return $this->issueList($request, $viewer, $scope, 'my_issues.html.twig', self::tabs('assigned', $viewer, $teamMembers));
    }

    /**
     * The issues given to a team the viewer is part of, whoever of the
     * team takes them.
     */
    #[Route('/teams', name: 'app_my_issues_teams', methods: ['GET', 'PUT'])]
    public function teams(Request $request, #[CurrentUser] User $viewer, TeamMemberRepository $teamMembers): Response
    {
        $scope = new ListScope($request->getBaseUrl().$request->getPathInfo(), self::DIMENSIONS, showProject: true, teamMemberId: $viewer->getId());

        return $this->issueList($request, $viewer, $scope, 'my_issues.html.twig', self::tabs('teams', $viewer, $teamMembers));
    }

    #[Route('/created', name: 'app_my_issues_created', methods: ['GET', 'PUT'])]
    public function created(Request $request, #[CurrentUser] User $viewer, TeamMemberRepository $teamMembers): Response
    {
        $scope = new ListScope($request->getBaseUrl().$request->getPathInfo(), self::DIMENSIONS, authorId: $viewer->getId(), showProject: true);

        return $this->issueList($request, $viewer, $scope, 'my_issues.html.twig', self::tabs('created', $viewer, $teamMembers));
    }

    /**
     * What the tabs read: the list shown, and whether the viewer is part
     * of a team, which the list of their teams is offered for.
     *
     * @return array{list: string, inTeams: bool}
     */
    private static function tabs(string $list, User $viewer, TeamMemberRepository $teamMembers): array
    {
        return ['list' => $list, 'inTeams' => $teamMembers->count(['user' => $viewer]) > 0];
    }
}
