<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Comment;
use App\Entity\Enum\MergeRequestState;
use App\Message\Command\Forge\RecordCommits;
use App\Message\Command\Forge\RecordMergeRequest;
use App\Message\Command\Issue\AddLink;
use App\Message\Command\Issue\UpdateIssue;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Forge\ForgeTestSupport;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * In the scene, MD-SPT-4, "The CSV export of orders is empty", waits
 * for Claire's acceptance; MD-SPT-6 is an internal task.
 */
final class IssueTest extends WebTestCase
{
    use KernelHelpers;

    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testTheWorkflowOfTheIssueIsADialogForWhoeverSeesIt(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $crawler = $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertSelectorExists('main aside a[href="/MD/SPT/issues/4/workflow"][data-turbo-frame="modal"]', 'a role held to the workflow opens the diagram too');
        self::assertSame('', trim($crawler->filter('turbo-frame#modal')->text()), 'the page alone holds no dialog');

        $this->client->request('GET', '/MD/SPT/issues/4/workflow');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'The CSV export of orders is empty', 'the dialog opens over the page');
        self::assertSelectorExists('turbo-frame#modal [role="dialog"] [data-controller="workflow-diagram"]');
        $source = $this->client->getCrawler()->filter('turbo-frame#modal [data-workflow-diagram-target="source"]')->text(null, false);
        self::assertStringContainsString('style p_to_test stroke:var(--color-brand-solid)', $source, 'the step of the issue is outlined');
        self::assertStringContainsString('Accept', $source, 'every action is named');
        self::assertSelectorTextNotContains('turbo-frame#modal [role="dialog"]', 'Strong line', 'the drawing alone, without its legend');

        $this->client->request('GET', '/MD/SPT/issues/4/workflow', server: ['HTTP_TURBO_FRAME' => 'modal']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('h1', 'the frame is answered the dialog alone');
        self::assertSelectorExists('turbo-frame#modal [role="dialog"]');

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues/4/workflow');
        self::assertSelectorExists('turbo-frame#modal [role="dialog"] [data-controller="workflow-diagram"]', 'the same for a role that moves freely');
        self::assertSelectorExists('main aside [aria-current="step"]', 'his menu draws the path, the step of the issue marked');

        $this->signInAs('sophie.bernard@travelia.test');
        $this->client->request('GET', '/MD/SPT/issues/4/workflow');
        self::assertResponseStatusCodeSame(403, 'not a member of the project');
    }

    public function testAClientSeesTheIssueAndTheActionsOfHerRole(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/MD/SPT/issues/4');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'The CSV export of orders is empty');
        self::assertSelectorTextSame('main [data-controller="clipboard"] [data-clipboard-target="source"]', 'MD-SPT-4', 'the key is what the button copies');
        self::assertSelectorExists('main [data-controller="clipboard"] button[data-action="clipboard#copy"] [role="tooltip"]');
        self::assertSelectorTextContains('main [data-controller="clipboard"] [role="tooltip"] [data-clipboard-target="idle"]', 'Copy the identifier');
        self::assertSelectorTextContains('main aside', 'Environment');
        self::assertSelectorTextContains('main', 'Accept');
        self::assertSelectorTextContains('main', 'Refuse');
        self::assertSelectorTextContains('main', 'created the issue');
        self::assertSelectorTextContains('main', 'changed the assignee: Nobody → Nadia Benali', 'assigned through the command in the fixtures');
        self::assertSelectorTextContains('main [aria-label="Path"]', 'New');
        self::assertSelectorExists('main [data-live-name-value="Issue:Activity"] [data-live-transition-param="accept"]', 'her actions are in the response box');
        self::assertSelectorNotExists('main aside [data-controller="panels"]', 'the step is not a menu for a role held to the workflow');
        self::assertSelectorNotExists('main aside [data-live-action-param="move"]');

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertSelectorExists('main aside [data-live-action-param="move"][data-live-place-param="qualified"]', 'the manager moves freely: the step is a menu of every place');
        self::assertSelectorExists('main aside [data-live-action-param="move"][data-live-place-param="to_release"]');
        self::assertSelectorNotExists('main [data-live-name-value="Issue:Activity"] [data-live-transition-param]', 'and no action is offered under the thread');
    }

    public function testACommentOrADescriptionBeingWrittenIsADraftThePageGuards(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');

        $this->client->request('GET', '/MD/SPT/issues/4');

        self::assertSelectorExists('main [data-live-name-value="Issue:Activity"][data-draft]');
        self::assertSelectorExists('main [data-live-name-value="Issue:Description"][data-draft]');
        self::assertSelectorExists('[data-controller="draft-guard"] template[data-draft-guard-target="template"]', 'the dialog waits in the page');
    }

    public function testPrivateCommentsAreForInternalsOnly(): void
    {
        $comment = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Comment::class)->findOneBy(['issue' => $this->scene->issue('MD-SPT-4'), 'private' => true]) ?? self::fail('no private comment');
        $url = '/MD/SPT/issues/4';

        $this->signInAs('nadia.benali@cairn.test');
        $this->client->request('GET', $url);
        self::assertSelectorTextContains('main', $comment->getBody());
        self::assertSelectorExists('main #comment-'.$comment->getId().' [title="Hidden from external users"]', 'the badge says Private, and why on hover');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', $comment->getBody());
    }

    public function testAnArchivedProjectShowsItsIssuesWithoutAnyControl(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertSelectorTextNotContains('body', 'is archived', 'no banner on an active project');

        $this->scene->shop->archive();
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        foreach (['claire.martin@maison-dupont.test', 'julie.admin@cairn.test'] as $email) {
            $this->signInAs($email);
            $this->client->request('GET', '/MD/SPT/issues/4');

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'This project is archived');
            self::assertSelectorTextContains('h1', 'The CSV export of orders is empty');
            // The workflow drawer still names the actions in its Mermaid source; the activity offers none.
            self::assertSelectorTextNotContains('main [data-live-name-value="Issue:Activity"]', 'Accept', $email);
            self::assertSelectorTextNotContains('main [data-live-name-value="Issue:Activity"]', 'Refuse', $email);
            self::assertSelectorNotExists('main [data-live-action-param="submit"]', $email);
            self::assertSelectorNotExists('main [data-live-action-param="apply"]', $email);
            self::assertSelectorNotExists('main [data-live-action-param="transition"]', $email);
            self::assertSelectorNotExists('main [data-live-action-param="move"]', $email);
        }
    }

    public function testAnIssueTheViewerMayNotSeeIsNotFound(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues/6');
        self::assertResponseStatusCodeSame(404, 'a private issue');

        $this->client->request('GET', '/MD/SPT/issues/999');
        self::assertResponseStatusCodeSame(404, 'no such number');

        $this->signInAs('sophie.bernard@travelia.test');
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertResponseStatusCodeSame(403, 'no role on the project');
    }

    public function testTheCodeOfTheIssueIsForInternalsOnly(): void
    {
        $repository = (new ForgeTestSupport(self::getContainer()->get(EntityManagerInterface::class)))->link($this->scene->shop);
        $commands = self::commandBus();
        $commands->dispatch(new RecordCommits((int) $repository->getId(), 'MD-SPT-4', 'main', 'https://gitlab.test/maison-dupont/shop/-/tree/main', [ForgeTestSupport::commit('a1b2c3d4e5f6'), ForgeTestSupport::commit('e4f5a6b7c8d9', 'test(export): 8 000 orders')]));
        $commands->dispatch(new RecordMergeRequest((int) $repository->getId(), 'MD-SPT-4', ForgeTestSupport::mergeRequest(MergeRequestState::Merged)));
        $url = '/MD/SPT/issues/4';

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main ol details summary', '2 commits');
        self::assertSelectorTextContains('main ol details', 'Open in GitLab');
        self::assertSelectorTextContains('main', 'Merge request !431');
        self::assertSelectorTextContains('main', 'Merged');
        self::assertSelectorTextContains('main aside', '!431', 'the card of the panel, folded, sums the code up');
        self::assertSelectorExists('main span[title="GitLab"]', 'the marker names the forge');
        self::assertSelectorExists('a[href="https://gitlab.test/maison-dupont/shop/-/commit/a1b2c3d4e5f6"]');

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'maison-dupont/shop');
        self::assertSelectorTextNotContains('main', '!431');
        self::assertSelectorTextNotContains('body', 'a1b2c3d4');
    }

    public function testTheThreadTellsTheDayOnceAndLinksAnIssueOnlyForWhoMaySeeIt(): void
    {
        $nadia = (int) $this->scene->user('nadia.benali@cairn.test')->getId();
        self::commandBus()->dispatch(new AddLink($nadia, 'MD-SPT-4', 'related', 'MD-SPT-1'));

        $this->signInAs('claire.martin@maison-dupont.test');
        $crawler = $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('main ol a[href="/MD/SPT/issues/1"]', 'the key of the linked issue leads to it');
        $days = $crawler->filter('main ol > li > time[datetime]')->each(static fn ($time): string => (string) $time->attr('datetime'));
        self::assertNotEmpty($days);
        self::assertSame(array_values(array_unique($days)), $days, 'a day is told once, above its moments');
        self::assertSelectorTextContains('main', 'To release', 'each action says the step it leads to');
        self::assertSelectorExists('aside nav a[aria-current="page"][href="/MD/SPT/issues/tracker/'.$this->scene->issue('MD-SPT-4')->getTracker()->getId().'"]', 'the navigation marks the category of the issue');

        self::commandBus()->dispatch(new UpdateIssue($nadia, 'MD-SPT-1', private: true));
        $this->client->request('GET', '/MD/SPT/issues/4');
        self::assertSelectorTextContains('main ol.relative', 'MD-SPT-1', 'the journal keeps the key it wrote');
        self::assertSelectorNotExists('main ol.relative a[href="/MD/SPT/issues/1"]', 'but leads nowhere the reader may not go');
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}
