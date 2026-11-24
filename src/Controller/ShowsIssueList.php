<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\Issue\IssueListFilters;
use App\Message\Command\User\RememberListView;
use App\Message\CommandBus;
use App\Repository\ListViewRepository;
use App\Twig\Components\IssueList\ListScope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\ValidationFailedException;

/**
 * What every route of an issue list answers, once it has fixed its scope.
 *
 * The address says how the list is arranged (IssueListFilters), so a list
 * as the user arranged it is an address to share; the address alone means
 * "my view of this list". The page is drawn once: a change of filter,
 * sort, page or view asks for the `issues` frame only and gets the results
 * alone, the columns of a kanban already drawn: the frame then swaps the
 * board it shows for the next one at once, never for placeholders that
 * fill one by one. The browser then tells the list, with a PUT at the same address,
 * to remember the arrangement; a GET never writes, so opening the address
 * someone shared changes nothing of one's own view. PUT is not a method a
 * form or another site can send without the browser asking first.
 *
 * For controllers extending AbstractController; it brings their constructor.
 */
trait ShowsIssueList
{
    public function __construct(
        private readonly ListViewRepository $views,
        private readonly CommandBus $commands,
    ) {
    }

    /**
     * @param array<string, mixed> $context what the page needs beyond `scope` and `filters`
     */
    private function issueList(Request $request, User $viewer, ListScope $scope, string $template, array $context = []): Response
    {
        if ($request->isMethod('PUT')) {
            parse_str($request->getContent(), $query);
            try {
                $this->commands->dispatch(new RememberListView((int) $viewer->getId(), $scope->path, IssueListFilters::fromQuery($query)->remembered()->queryString()));
            } catch (ValidationFailedException) {
                // An arrangement too long to keep: the list shows it, and remembers the previous one.
                return new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $frame = 'issues' === $request->headers->get('Turbo-Frame');
        if (!$frame && 0 === $request->query->count()) {
            $remembered = $this->views->findOne($viewer, $scope->path)?->getQuery();
            if (null !== $remembered) {
                return $this->redirect($scope->path.'?'.$remembered);
            }
        }

        $response = $this->render($frame ? 'issue/_results.html.twig' : $template, [...$context, 'scope' => $scope, 'filters' => IssueListFilters::fromQuery($request->query->all()), 'frame' => $frame]);
        $response->setVary('Turbo-Frame', false);

        return $response;
    }
}
