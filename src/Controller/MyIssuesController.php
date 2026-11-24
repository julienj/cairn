<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Twig\Components\IssueList\ListScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The issues across every project the signed-in user sees: all of them,
 * whoever they are assigned to, those assigned to them, those they
 * created. One route per scope; the address
 * says how the list is arranged, and ShowsIssueList answers.
 */
#[Route('/issues')]
final class MyIssuesController extends AbstractController
{
    use ShowsIssueList;

    private const array DIMENSIONS = ['place', 'project', 'criticality'];

    #[Route('/all', name: 'app_my_issues_all', methods: ['GET', 'PUT'])]
    public function all(Request $request, #[CurrentUser] User $viewer): Response
    {
        $scope = new ListScope($request->getBaseUrl().$request->getPathInfo(), [...self::DIMENSIONS, 'assignee'], showProject: true);

        return $this->issueList($request, $viewer, $scope, 'my_issues.html.twig', ['list' => 'all']);
    }

    #[Route('/assigned', name: 'app_my_issues_assigned', methods: ['GET', 'PUT'])]
    public function assigned(Request $request, #[CurrentUser] User $viewer): Response
    {
        $scope = new ListScope($request->getBaseUrl().$request->getPathInfo(), self::DIMENSIONS, assigneeId: $viewer->getId(), showProject: true);

        return $this->issueList($request, $viewer, $scope, 'my_issues.html.twig', ['list' => 'assigned']);
    }

    #[Route('/created', name: 'app_my_issues_created', methods: ['GET', 'PUT'])]
    public function created(Request $request, #[CurrentUser] User $viewer): Response
    {
        $scope = new ListScope($request->getBaseUrl().$request->getPathInfo(), self::DIMENSIONS, authorId: $viewer->getId(), showProject: true);

        return $this->issueList($request, $viewer, $scope, 'my_issues.html.twig', ['list' => 'created']);
    }
}
