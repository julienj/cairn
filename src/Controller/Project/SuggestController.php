<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Workspace;
use App\Message\Query\Issue\SearchIssues;
use App\Message\Query\Project\ListProjectMembers;
use App\Message\Query\Project\ListProjectTeams;
use App\Message\QueryBus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the editor offers after `#` (issues of the project the user may
 * see, closed ones included) and after `@` (the teams that hold a role on
 * the project, then its members).
 */
#[Route('/{workspaceKey}/{projectKey}/suggest', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
#[IsGranted('view', subject: 'project')]
final class SuggestController extends AbstractController
{
    private const int LIMIT = 8;

    #[Route('/issues', name: 'app_suggest_issues', methods: ['GET'])]
    public function issues(
        #[CurrentProject]
        Project $project,
        #[CurrentUser]
        User $viewer,
        QueryBus $queries,
        #[MapQueryParameter]
        string $q = '',
    ): JsonResponse {
        $page = $queries->ask(new SearchIssues($viewer, projectId: $project->getId(), q: $q, closed: true));

        return $this->json(array_map(
            static fn (Issue $issue) => ['key' => $issue->getKey(), 'title' => $issue->getTitle()],
            \array_slice($page->items, 0, self::LIMIT),
        ));
    }

    #[Route('/users', name: 'app_suggest_users', methods: ['GET'])]
    public function users(
        #[CurrentProject]
        Project $project,
        QueryBus $queries,
        TranslatorInterface $translator,
        #[MapQueryParameter]
        string $q = '',
    ): JsonResponse {
        $text = mb_strtolower(trim($q));
        $members = array_filter(
            $queries->ask(new ListProjectMembers($project)),
            static fn (User $user) => '' === $text || str_contains(mb_strtolower($user->getFullName()), $text),
        );

        // The teams first: there are few, and a name typed finds them among the people.
        $teams = array_filter(
            $queries->ask(new ListProjectTeams($project)),
            static fn (Team $team) => '' === $text || str_contains(mb_strtolower($team->getName()), $text),
        );
        $hint = $translator->trans('issue.show.team');

        return $this->json(\array_slice([
            ...array_map(static fn (Team $team) => ['id' => $team->getId(), 'name' => $team->getName(), 'team' => true, 'hint' => $hint], array_values($teams)),
            ...array_map(static fn (User $user) => ['id' => $user->getId(), 'name' => $user->getFullName()], array_values($members)),
        ], 0, self::LIMIT));
    }
}
