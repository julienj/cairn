<?php

declare(strict_types=1);

namespace App\MessageHandler\Command\Tag;

use App\Message\Command\Tag\DeleteTag;
use App\MessageHandler\Command\ProjectActor;
use App\Repository\IssueTagRepository;
use App\Repository\TagRepository;
use App\Security\Permission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The tag goes with what tied it to its issues, in one statement whatever
 * their number: no issue is touched, journaled nor notified. A list kept
 * filtered by it ignores a tag it no longer knows.
 */
#[AsMessageHandler(bus: 'command.bus')]
final class DeleteTagHandler
{
    public function __construct(
        private readonly TagRepository $tags,
        private readonly IssueTagRepository $issueTags,
        private readonly ProjectActor $actor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(DeleteTag $command): void
    {
        $tag = $this->tags->find($command->tagId) ?? throw TagException::notFound('Tag', $command->tagId);
        $this->actor->denyUnlessGranted($command->actorId, Permission::TagManage, $tag->getProject());

        $this->issueTags->removeTag($tag);
        $this->entityManager->remove($tag);
    }
}
