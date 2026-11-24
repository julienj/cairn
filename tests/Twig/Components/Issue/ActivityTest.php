<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Comment;
use App\Entity\Enum\ActivityKind;
use App\Entity\Issue;
use App\Entity\User;
use App\Message\Command\Forge\RecordCommits;
use App\Message\Command\Issue\MoveIssue;
use App\Message\CommandBus;
use App\Tests\MessageHandler\Command\Forge\ForgeTestSupport;
use App\Tests\Scene\ShopScene;
use App\Twig\Components\Issue\Activity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * MD-SPT-4 waits on "To test": the client accepts or refuses, a
 * refusal needs a comment from them.
 */
final class ActivityTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Issue $issue;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->issue = $this->scene->issue('MD-SPT-4');
    }

    public function testTheDecisionOffersTheActionsOfTheViewersRoleAboveTheCommentBox(): void
    {
        $client = (string) $this->activity('claire.martin@maison-dupont.test')->render();
        self::assertStringContainsString('What would you like to do?', $client);
        self::assertStringContainsString('data-live-action-param="apply" data-live-transition-param="accept"', $client);
        self::assertStringContainsString('data-live-action-param="apply" data-live-transition-param="refuse"', $client);
        self::assertStringContainsString('data-live-action-param="submit"', $client, 'the comment box, on its own');
        self::assertStringNotContainsString('Comment privately', $client);

        // The manager moves freely and takes no action; the developer has none from "To test".
        foreach (['thomas.girard@cairn.test', 'nadia.benali@cairn.test'] as $internal) {
            $render = (string) $this->activity($internal)->render();
            self::assertStringNotContainsString('data-live-action-param="apply"', $render, $internal);
            self::assertStringNotContainsString('What would you like to do?', $render, 'no action, no decision: the comment box alone');
            self::assertStringContainsString('data-live-action-param="submit"', $render);
        }

        $readOnly = (string) $this->activity('paul.roussel@maison-dupont.test')->render();
        self::assertStringNotContainsString('data-live-action-param="submit"', $readOnly);
        self::assertStringNotContainsString('Accept', $readOnly, 'the actions of others are nobody\'s business');
        self::assertStringContainsString('created the issue', $readOnly, 'the thread is shown to everyone');
    }

    public function testEachActionSaysWhatItDoes(): void
    {
        $claire = 'claire.martin@maison-dupont.test';

        $new = (string) $this->activityOn('MD-SPT-1', $claire)->render();
        self::assertStringNotContainsString('data-live-action-param="apply"', $new, 'qualifying is for internal users: Claire only comments');
        self::assertStringNotContainsString('Qualify', $new);

        self::assertStringContainsString('The fix is accepted', (string) $this->activity($claire)->render(), 'the description of the configuration');

        $done = (string) $this->activityOn('MD-SPT-5', $claire)->render();
        self::assertStringContainsString('data-live-transition-param="reopen"', $done);
    }

    public function testAnActionAppliesAtOnceAndKeepsTheCommentBeingWritten(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');
        $component->set('body', 'Draft in progress');

        $component->call('apply', ['transition' => 'accept']);
        $render = $component->render();

        self::assertSame(200, $component->response()->getStatusCode(), 'no redirect');
        self::assertSame('to_release', $this->reloaded()->getPlace());
        self::assertStringContainsString('Draft in progress', (string) $render, 'the comment box is another matter');
        self::assertNull($component->getDispatchedBrowserEvent($render, 'editor:reset'));
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
        self::assertSame(['message' => 'MD-SPT-4: "Accept" applied.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
    }

    public function testWhoMovesFreelyIsRefusedAnAction(): void
    {
        $component = $this->activity('thomas.girard@cairn.test');

        $component->call('apply', ['transition' => 'refuse']);

        self::assertSame('to_test', $this->reloaded()->getPlace(), 'the action is the client\'s: the manager moves the issue from the step menu');
    }

    public function testARefusalAsksForItsCommentInTheDecision(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->call('apply', ['transition' => 'refuse']);
        $render = (string) $component->render();
        self::assertSame('to_test', $this->reloaded()->getPlace(), 'nothing applied yet');
        self::assertStringContainsString('a comment is required to refuse', $render, 'the prompt of the configuration');
        self::assertStringContainsString('data-live-action-param="confirm"', $render);
        self::assertStringNotContainsString('data-live-action-param="apply"', $render, 'one decision at a time');

        $component->call('confirm');
        self::assertSame('to_test', $this->reloaded()->getPlace(), 'still nothing without the comment');
        self::assertStringContainsString('role="alert"', (string) $component->render());

        $component->set('reason', 'Still empty in production.')->call('confirm');
        $render = $component->render();

        self::assertSame('in_progress', $this->reloaded()->getPlace());
        self::assertStringContainsString('applied &quot;Refuse&quot;', (string) $render);
        self::assertStringContainsString('Still empty in production.', (string) $render);
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertNull($activity->awaiting);
        self::assertSame('', $activity->reason);
        self::assertSame(['message' => 'MD-SPT-4: "Refuse" applied.'], $component->getDispatchedBrowserEvent($render, 'toast:show')['payload'] ?? null);
    }

    public function testARefusalWrittenWhileTheIssueMovedIsKeptAndRefused(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');
        $component->call('apply', ['transition' => 'refuse']);
        $thomas = $this->scene->user('thomas.girard@cairn.test');
        self::getContainer()->get(CommandBus::class)->dispatch(new MoveIssue((int) $thomas->getId(), 'MD-SPT-4', 'qualified'));

        $component->set('reason', 'Still empty in production.')->call('confirm');
        $render = (string) $component->render();

        self::assertStringContainsString('could not be applied', $render);
        self::assertStringContainsString('data-live-action-param="confirm"', $render, 'the decision stays open');
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame('Still empty in production.', $activity->reason, 'what was written is not lost');
        self::assertSame('qualified', $this->reloaded()->getPlace());
    }

    public function testARefusalBeingWrittenIsGivenUp(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->call('apply', ['transition' => 'refuse'])->call('cancelDecision');

        self::assertStringContainsString('data-live-transition-param="accept"', (string) $component->render());
        self::assertSame('to_test', $this->reloaded()->getPlace());
    }

    public function testAnActionOfAnotherRoleIsRefused(): void
    {
        $component = $this->activity('nadia.benali@cairn.test');

        $component->call('apply', ['transition' => 'accept']);

        self::assertSame('to_test', $this->reloaded()->getPlace());
    }

    public function testAClientCommentIsPublicWhateverTheFlag(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->set('body', 'Thanks, I will look tomorrow.')->set('private', true)->call('submit');

        self::assertStringContainsString('Thanks, I will look tomorrow.', (string) $component->render());
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame('Thanks, I will look tomorrow.', $comment?->getBody());
        self::assertFalse($comment->isPrivate());
    }

    public function testACommentIsWrittenInMarkdownAndTheEditorIsToldToReset(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->set('body', 'Still **empty**, like #MD-SPT-2.')->call('submit');
        $render = $component->render();

        self::assertStringContainsString('Still <strong>empty</strong>, like <a class="issue-reference" href="/MD/SPT/issues/2"', (string) $render);
        self::assertNotNull($component->getDispatchedBrowserEvent($render, 'editor:reset'), 'the editor, outside the morph, empties itself');
    }

    public function testFilesSentWithACommentGoUnderIt(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');

        $component->call('attach', [], ['files' => [FilesTest::file('quote.pdf'), FilesTest::file('draft.txt')]]);
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertCount(2, $activity->pending);
        self::assertStringContainsString('draft.txt', (string) $component->render());

        $component->call('discard', ['uuid' => $activity->pending[1]]);
        $component->set('body', 'Here is the quote.')->call('submit');
        $render = (string) $component->render();

        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame('Here is the quote.', $comment?->getBody());
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame([], $activity->pending, 'the composer starts afresh');
        self::assertStringContainsString('quote.pdf', $render);
        self::assertStringNotContainsString('draft.txt', $render, 'a discarded file stays out');
    }

    public function testAnEmptyCommentIsRefused(): void
    {
        $component = $this->activity('nadia.benali@cairn.test');

        $component->set('body', '   ')->call('submit');

        self::assertStringContainsString('The comment is empty.', (string) $component->render());
    }

    public function testTheCommentBeingWrittenSurvivesAChangeMadeElsewhere(): void
    {
        $component = $this->activity('thomas.girard@cairn.test');
        $component->set('body', 'Draft in progress');

        $component->emit('issue:changed');

        self::assertStringContainsString('Draft in progress', (string) $component->render());
    }

    public function testTheFilesOfACommentAreRemovedByWhoMayEditIt(): void
    {
        $claire = $this->activity('claire.martin@maison-dupont.test');
        $claire->call('attach', [], ['files' => [FilesTest::file('quote.pdf')]]);
        $activity = $claire->component();
        self::assertInstanceOf(Activity::class, $activity);
        $remove = \sprintf('data-live-action-param="removeAttachment" data-live-uuid-param="%s"', $activity->pending[0]);
        $claire->set('body', 'Here is the quote.')->call('submit');
        self::assertStringContainsString($remove, (string) $claire->render());

        $render = (string) $this->activity('nadia.benali@cairn.test')->render();
        self::assertStringContainsString('quote.pdf', $render);
        self::assertStringNotContainsString($remove, $render, 'Nadia did not write that comment');
        self::assertStringContainsString($remove, (string) $this->activity('thomas.girard@cairn.test')->render(), 'comment.edit');
    }

    public function testACommentIsRewrittenInPlaceWithoutLosingTheDraft(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');
        $id = $this->postedComment($component, 'Thanks, it is blocking.');
        $component->set('body', 'Draft in progress');
        self::assertStringContainsString(\sprintf('data-live-action-param="editComment" data-live-id-param="%d"', $id), (string) $component->render());

        $component->call('editComment', ['id' => $id]);
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame($id, $activity->editingCommentId);
        $component->call('attach', [], ['files' => [FilesTest::file('details.pdf')]]);
        $component->set('commentBody', 'Thanks, **really** blocking.')->call('saveComment');
        $render = (string) $component->render();
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertNull($activity->error);

        self::assertSame('Thanks, **really** blocking.', $this->reloadedComment($id)?->getBody());
        self::assertStringContainsString('edited on', $render);
        self::assertStringContainsString('edited their comment', $render);
        self::assertStringContainsString('details.pdf', $render, 'a file sent while rewriting goes with the comment');
        self::assertStringContainsString('Draft in progress', $render, 'the composer keeps its draft');
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertNull($activity->editingCommentId);
        self::assertSame([], $activity->editPending);
    }

    public function testACommentIsDeletedAfterConfirmationAndItsTextStaysInTheThread(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');
        $body = 'To delete, sent by mistake.';
        $id = $this->postedComment($component, $body);

        $component->call('confirmDelete', ['id' => $id]);
        self::assertStringContainsString('Delete this comment?', (string) $component->render());

        $component->call('deleteComment');
        $render = $component->render();

        self::assertNull($this->reloadedComment($id));
        self::assertStringContainsString('deleted their comment', (string) $render);
        self::assertStringContainsString($body, (string) $render, 'the deleted text is still readable');
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
    }

    public function testEditingControlsFollowTheRightOnTheComment(): void
    {
        $comments = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class);
        $nadiaComment = $comments->findOneBy(['issue' => $this->issue, 'author' => $this->scene->user('nadia.benali@cairn.test')]) ?? self::fail('no comment of Nadia');
        $claireComment = $comments->findOneBy(['issue' => $this->issue, 'author' => $this->scene->user('claire.martin@maison-dupont.test')]) ?? self::fail('no comment of Claire');

        $component = $this->activity('nadia.benali@cairn.test');
        $developer = (string) $component->render();
        $activity = $component->component();
        self::assertInstanceOf(Activity::class, $activity);
        self::assertSame([(int) $nadiaComment->getId()], $activity->getEditableComments(), 'Nadia edits her own comment only');
        self::assertStringContainsString(\sprintf('data-live-action-param="editComment" data-live-id-param="%d"', $nadiaComment->getId()), $developer, 'her own');
        self::assertStringNotContainsString(\sprintf('data-live-id-param="%d"', $claireComment->getId()), $developer, 'not hers');

        $paul = (string) $this->activity('paul.roussel@maison-dupont.test')->render();
        self::assertStringNotContainsString('data-live-action-param="editComment"', $paul);
        self::assertStringNotContainsString('data-live-action-param="confirmDelete"', $paul);
    }

    public function testEachCommentHasItsOwnAddressThatItsDateLinksTo(): void
    {
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)
            ->findOneBy(['issue' => $this->issue, 'author' => $this->scene->user('claire.martin@maison-dupont.test')]) ?? self::fail('no comment of Claire');
        $anchor = 'comment-'.$comment->getId();

        // Paul only reads the issue: the address is for whoever sees the comment, not for who may edit it.
        $render = (string) $this->activity('paul.roussel@maison-dupont.test')->render();

        self::assertStringContainsString(\sprintf('id="%s"', $anchor), $render);
        self::assertStringContainsString(\sprintf('<a href="#%s"', $anchor), $render, 'the date is the link');
        self::assertStringContainsString(\sprintf('data-controller="clipboard" data-clipboard-text-value="#%s"', $anchor), $render, 'and its link is copied in one click');
    }

    /** Posts a comment from the composer and returns its id. */
    public function testWhoReadsChoosesWhatToReadForEveryIssue(): void
    {
        $manager = 'thomas.girard@cairn.test';
        $repository = (new ForgeTestSupport(self::getContainer()->get(EntityManagerInterface::class)))->link($this->issue->getProject());
        self::getContainer()->get(CommandBus::class)->dispatch(new RecordCommits((int) $repository->getId(), $this->issue->getKey(), 'main', 'https://gitlab.test/maison-dupont/shop/-/tree/main', [ForgeTestSupport::commit('a1b2c3d4e5f6')]));
        $component = $this->activity($manager);
        $this->postedComment($component, 'A comment of the manager.');
        $render = (string) $component->render();
        self::assertStringContainsString('created the issue', $render);
        self::assertStringContainsString('a1b2c3d4', $render);
        self::assertStringContainsString('A comment of the manager.', $render);
        self::assertStringContainsString('data-live-kind-param="code"', $render);
        self::assertStringContainsString('Show all', $render);

        $component->call('toggleActivity', ['kind' => 'code']);
        $render = (string) $component->render();
        self::assertStringNotContainsString('a1b2c3d4', $render, 'the commits are hidden');
        self::assertStringContainsString('created the issue', $render);
        self::assertSame([ActivityKind::Code], $this->hiddenBy($manager));
        self::assertStringContainsString('Show changes, comments', $render, 'the menu says what is shown, not what is hidden');

        $component->call('toggleActivity', ['kind' => 'comments'])->call('toggleActivity', ['kind' => 'changes']);
        $render = (string) $component->render();
        self::assertStringContainsString('Nothing to show with these filters.', $render);
        self::assertStringContainsString('Show nothing', $render);
        self::assertStringContainsString('data-live-action-param="submit"', $render, 'the comment box stays');

        // The choice follows the person to the other issues, and a kind can be shown again.
        self::assertStringContainsString('Nothing to show', (string) $this->activityOn('MD-SPT-3', $manager)->render());
        $component->call('toggleActivity', ['kind' => 'comments'])->call('toggleActivity', ['kind' => 'code'])->call('toggleActivity', ['kind' => 'changes']);
        self::assertSame([], $this->hiddenBy($manager));
        self::assertStringContainsString('a1b2c3d4', (string) $component->render());
    }

    public function testTheCommentOfAnActionFollowsTheCommentsAndTheActionTheChanges(): void
    {
        $component = $this->activity('claire.martin@maison-dupont.test');
        $component->call('apply', ['transition' => 'refuse'])->set('reason', 'Still empty in production.')->call('confirm');

        $component->call('toggleActivity', ['kind' => 'changes']);
        $render = (string) $component->render();
        self::assertStringNotContainsString('applied &quot;Refuse&quot;', $render);
        self::assertStringContainsString('Still empty in production.', $render);

        $component->call('toggleActivity', ['kind' => 'changes'])->call('toggleActivity', ['kind' => 'comments']);
        $render = (string) $component->render();
        self::assertStringContainsString('applied &quot;Refuse&quot;', $render);
        self::assertStringNotContainsString('Still empty in production.', $render);
    }

    public function testAnUnknownKindChangesNothing(): void
    {
        $component = $this->activity('thomas.girard@cairn.test');
        $component->call('toggleActivity', ['kind' => 'bogus']);
        self::assertSame([], $this->hiddenBy('thomas.girard@cairn.test'));
    }

    /**
     * @return list<ActivityKind>
     */
    private function hiddenBy(string $email): array
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(User::class, $this->scene->user($email)->getId())?->getHiddenActivity() ?? self::fail('user vanished');
    }

    private function postedComment(TestLiveComponent $component, string $body): int
    {
        $component->set('body', $body)->call('submit');
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->issue], ['id' => 'DESC']);
        self::assertSame($body, $comment?->getBody());

        return (int) $comment->getId();
    }

    /** The comment as the database has it; a removed one is already out of the identity map. */
    private function reloadedComment(int $id): ?Comment
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $comment = $entityManager->find(Comment::class, $id);
        if (null !== $comment) {
            $entityManager->refresh($comment);
        }

        return $comment;
    }

    private function activity(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Activity', ['issue' => $this->issue])->actingAs($this->scene->user($email));
    }

    private function activityOn(string $key, string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Activity', ['issue' => $this->scene->issue($key)])->actingAs($this->scene->user($email));
    }

    private function reloaded(): Issue
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return $entityManager->find(Issue::class, $this->issue->getId()) ?? self::fail('issue vanished');
    }
}
