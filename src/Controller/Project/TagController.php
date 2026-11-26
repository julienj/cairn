<?php

declare(strict_types=1);

namespace App\Controller\Project;

use App\Controller\OpensDialog;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\Workspace;
use App\Form\Tag\TagData;
use App\Form\Tag\TagType;
use App\Message\Command\Tag\CreateTag;
use App\Message\Command\Tag\DeleteTag;
use App\Message\Command\Tag\UpdateTag;
use App\Message\CommandBus;
use App\MessageHandler\Command\Tag\TagException;
use App\Repository\IssueTagRepository;
use App\Repository\TagRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The tags of a project, for who manages them: the list, and a dialog that
 * creates one or changes one (its name, its colour, deleting it), which
 * has an address of its own and renders the list with it open. Issues are
 * tagged from their own page, their creation or the selection page.
 */
#[Route('/{workspaceKey}/{projectKey}/settings/tags', requirements: ['workspaceKey' => Workspace::KEY_FORMAT, 'projectKey' => Project::KEY_FORMAT])]
#[IsGranted('tag.manage', subject: 'project')]
final class TagController extends AbstractController
{
    use OpensDialog;

    public function __construct(
        private readonly CommandBus $commands,
        private readonly TagRepository $tags,
        private readonly IssueTagRepository $issueTags,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_project_settings_tags', methods: ['GET'])]
    public function list(#[CurrentProject] Project $project, Request $request): Response
    {
        return $this->renderList($request, $project, null, null);
    }

    #[Route('/new', name: 'app_project_settings_tag_new', methods: ['GET', 'POST'])]
    public function new(#[CurrentProject] Project $project, #[CurrentUser] User $actor, Request $request): Response
    {
        $data = new TagData();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(TagType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && null !== $data->colour) {
            try {
                $tag = $this->commands->dispatch(new CreateTag((int) $actor->getId(), (int) $project->getId(), (string) $data->name, $data->colour));
                \assert($tag instanceof Tag);
                $this->addFlash('success', $this->translator->trans('tag.created', ['%name%' => $tag->getName()]));

                return $this->redirectToRoute('app_project_settings_tags', self::route($project));
            } catch (TagException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderList($request, $project, $form, null);
    }

    #[Route('/{tag}', name: 'app_project_settings_tag', requirements: ['tag' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(#[CurrentProject] Project $project, #[MapEntity(id: 'tag')] Tag $tag, #[CurrentUser] User $actor, Request $request): Response
    {
        $this->assertOfProject($tag, $project);
        $data = new TagData();
        $data->name = $tag->getName();
        $data->colour = $tag->getColour();
        // sent to the address of the dialog, not to the page it is loaded over
        $form = $this->createForm(TagType::class, $data, ['action' => $request->getRequestUri()]);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->commands->dispatch(new UpdateTag((int) $actor->getId(), (int) $tag->getId(), (string) $data->name, $data->colour));
                $this->addFlash('success', $this->translator->trans('tag.saved'));

                return $this->redirectToRoute('app_project_settings_tags', self::route($project));
            } catch (TagException $e) {
                $form->addError(new FormError($e->trans($this->translator)));
            }
        }

        return $this->renderList($request, $project, $form, $tag);
    }

    /**
     * Deletes the tag; the issues that carried it carry it no more.
     */
    #[Route('/{tag}/delete', name: 'app_project_settings_tag_delete', requirements: ['tag' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid('submit')]
    public function delete(#[CurrentProject] Project $project, #[MapEntity(id: 'tag')] Tag $tag, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->assertOfProject($tag, $project);
        $name = $tag->getName();
        $this->commands->dispatch(new DeleteTag((int) $actor->getId(), (int) $tag->getId()));
        $this->addFlash('success', $this->translator->trans('tag.deleted', ['%name%' => $name]));

        return $this->redirectToRoute('app_project_settings_tags', self::route($project));
    }

    /**
     * @param FormInterface<TagData>|null $form the dialog, when it is open
     * @param Tag|null                    $tag  the tag it changes, none when it creates one
     */
    private function renderList(Request $request, Project $project, ?FormInterface $form, ?Tag $tag): Response
    {
        return $this->renderWithDialog($request, 'project/settings/tags.html.twig', [
            'project' => $project,
            'form' => $form,
            'dialogTag' => $tag,
            'dialogIssueCount' => null === $tag ? 0 : $this->issueTags->count(['tag' => $tag]),
        ], fn (): array => [
            'tags' => $this->tags->findByProject($project),
            'issueCounts' => $this->issueTags->countByTag($project),
        ], $form);
    }

    private function assertOfProject(Tag $tag, Project $project): void
    {
        if ($tag->getProject()->getId() !== $project->getId()) {
            throw new NotFoundHttpException(\sprintf('Tag %d is not a tag of %s.', $tag->getId(), $project->getIssuePrefix()));
        }
    }

    /**
     * @return array{workspaceKey: string, projectKey: string}
     */
    private static function route(Project $project): array
    {
        return ['workspaceKey' => $project->getWorkspace()->getKey(), 'projectKey' => $project->getKey()];
    }
}
