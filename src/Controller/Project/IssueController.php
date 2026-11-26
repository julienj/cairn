<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Controller\OpensDialog;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Query\Issue\IssueDetail;
use App\Message\Query\Issue\ShowIssue;
use App\Message\QueryBus;
use App\Repository\IssueReportRepository;
use App\Workflow\WorkflowProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The issue page, and over it the dialog that draws the workflow of the
 * issue, the step it stands on outlined: for whoever sees the issue, the
 * roles held to the workflow as those that move freely. An issue the
 * viewer may not see is not found, so that private issues cannot be
 * probed.
 */
#[IsGranted('view', subject: 'project')]
final class IssueController extends AbstractController
{
    use OpensDialog;

    private const array REQUIREMENTS = ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT, 'number' => '\d+'];

    public function __construct(
        private readonly QueryBus $queries,
    ) {
    }

    #[Route('/{workspaceKey}/{projectKey}/issues/{number}', name: 'app_issue_show', requirements: self::REQUIREMENTS, methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        int $number,
    ): Response {
        return $this->renderWithDialog($request, 'project/issue.html.twig', ['project' => $project, 'detail' => $this->detail($project, $viewer, $number), 'workflow' => null, 'report' => null], static fn (): array => []);
    }

    /**
     * What the widget noted on the site the issue was reported from, for
     * whoever sees the issue: a 404 for an issue that has no report.
     */
    #[Route('/{workspaceKey}/{projectKey}/issues/{number}/report', name: 'app_issue_report', requirements: self::REQUIREMENTS, methods: ['GET'])]
    public function report(
        Request $request,
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        int $number,
        IssueReportRepository $reports,
    ): Response {
        $detail = $this->detail($project, $viewer, $number);
        $report = $reports->reportOf($detail->issue) ?? throw $this->createNotFoundException(\sprintf('%s has no technical report.', $detail->issue->getKey()));

        return $this->renderWithDialog($request, 'project/issue.html.twig', ['project' => $project, 'detail' => $detail, 'workflow' => null, 'report' => $report], static fn (): array => []);
    }

    #[Route('/{workspaceKey}/{projectKey}/issues/{number}/workflow', name: 'app_issue_workflow', requirements: self::REQUIREMENTS, methods: ['GET'])]
    public function workflow(
        Request $request,
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        int $number,
        WorkflowProvider $workflows,
    ): Response {
        $detail = $this->detail($project, $viewer, $number);

        return $this->renderWithDialog($request, 'project/issue.html.twig', [
            'project' => $project,
            'detail' => $detail,
            'workflow' => $workflows->definition($detail->issue->getTracker()->getWorkflowId()),
            'report' => null,
        ], static fn (): array => []);
    }

    private function detail(Project $project, User $viewer, int $number): IssueDetail
    {
        return $this->queries->ask(new ShowIssue($viewer, (int) $project->getId(), $number))
            ?? throw $this->createNotFoundException(\sprintf('No issue %s-%d for this viewer.', $project->getIssuePrefix(), $number));
    }
}
