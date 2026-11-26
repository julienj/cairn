<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Attachment;
use App\Entity\Enum\Criticality;
use App\Entity\Issue;
use App\Entity\Project;
use App\Entity\Tag;
use App\Entity\Team;
use App\Entity\Tracker;
use App\Message\Command\Team\CreateTeam;
use App\Message\Command\Team\GrantTeamAccess;
use App\Message\CommandBus;
use App\Repository\AttachmentRepository;
use App\Repository\IssueFieldValueRepository;
use App\Repository\IssueRepository;
use App\Repository\IssueTagRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * In the scene, the Maison Dupont shop (MD-SPT) has ten issues, a public
 * "Bug" tracker on the bug workflow (environment required, estimate
 * internal only) and a private "Internal task" one.
 */
final class CreateFormTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private ShopScene $scene;
    private Project $shop;
    private Tracker $bugs;
    private Tracker $tasks;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scene = ShopScene::load(self::getContainer());
        $this->shop = $this->scene->shop;
        $this->bugs = $this->scene->bugs;
        $this->tasks = $this->scene->tasks;
    }

    public function testAClientPicksAPublicTrackerAndCreatesAnIssue(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test');

        $rendered = (string) $component->render();
        self::assertStringContainsString('Bug', $rendered);
        self::assertStringNotContainsString('Internal task', $rendered, 'private trackers are not offered');
        self::assertStringNotContainsString('issue[title]', $rendered, 'no form before the choice');

        $rendered = (string) $component->set('trackerId', $this->bugs->getId())->render();
        self::assertStringContainsString('issue[fields][environment]', $rendered);
        self::assertStringNotContainsString('issue[fields][estimate]', $rendered, 'internal-only field');
        self::assertStringNotContainsString('issue[assignee]', $rendered, 'clients do not assign');
        self::assertStringNotContainsString('issue[private]', $rendered, 'clients do not hide');

        $component->submitForm(['issue' => [
            'title' => '  The cart empties itself  ',
            'description' => 'After ten minutes, the cart is empty.',
            'fields' => ['environment' => 'prod', 'url' => 'https://shop.test/cart'],
            'criticality' => 'high',
        ]], 'save');

        $response = $component->response();
        self::assertSame(302, $response->getStatusCode(), $response->getContent() ?: '');
        self::assertSame('/MD/SPT/issues/11', $response->headers->get('Location'));

        $issue = $this->issue('MD-SPT-11');
        self::assertSame('The cart empties itself', $issue->getTitle());
        self::assertSame(Criticality::High, $issue->getCriticality());
        self::assertEquals(['environment' => 'prod', 'url' => 'https://shop.test/cart'], self::getContainer()->get(IssueFieldValueRepository::class)->valuesOf($issue));
        self::assertSame('new', $issue->getPlace());
        self::assertFalse($issue->isPrivate());
        self::assertSame('claire.martin@maison-dupont.test', $issue->getAuthor()->getEmail());
    }

    public function testAnIssueIsCreatedWithTheTagsTicked(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test')->set('trackerId', $this->bugs->getId());
        self::assertStringNotContainsString('issue[tags][]', (string) $component->render(), 'a project without tags asks for none');

        $back = $this->scene->tag('Back office');
        $front = $this->scene->tag('Front');
        $this->scene->tag('Mobile');
        $component = $this->form('claire.martin@maison-dupont.test')->set('trackerId', $this->bugs->getId());
        self::assertStringContainsString('issue[tags][]', (string) $component->render());

        $component->submitForm(['issue' => [
            'title' => 'The cart empties itself',
            'description' => 'After ten minutes, the cart is empty.',
            'fields' => ['environment' => 'prod'],
            'tags' => [(string) $back->getId(), (string) $front->getId()],
        ]], 'save');

        self::assertSame(302, $component->response()->getStatusCode(), $component->response()->getContent() ?: '');
        $names = array_map(static fn (Tag $tag): string => $tag->getName(), self::getContainer()->get(IssueTagRepository::class)->tagsOf($this->issue('MD-SPT-11')));
        self::assertSame(['Back office', 'Front'], $names);
    }

    public function testCreateAndContinueLeadsBackToTheNewIssuePage(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test');
        $component->set('trackerId', $this->bugs->getId());
        self::assertStringContainsString('Create and continue', (string) $component->render());

        $component->submitForm(['issue' => [
            'title' => 'The cart empties itself',
            'description' => 'After ten minutes, the cart is empty.',
            'fields' => ['environment' => 'prod'],
            'criticality' => 'normal',
        ]]);
        $component->call('save', ['continue' => true]);

        $response = $component->response();
        self::assertSame(302, $response->getStatusCode(), $response->getContent() ?: '');
        self::assertSame('/MD/SPT/issues/new', $response->headers->get('Location'));
        self::assertSame('The cart empties itself', $this->issue('MD-SPT-11')->getTitle());
    }

    public function testFilesSentWhileWritingGoToTheNewIssue(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test');
        $component->set('trackerId', $this->bugs->getId());

        $component->call('attach', [], ['files' => [FilesTest::file('trace.log')]]);
        self::assertStringContainsString('trace.log', (string) $component->render());
        $component->submitForm(['issue' => [
            'title' => 'The cart empties itself',
            'description' => 'See the trace.',
            'fields' => ['environment' => 'prod'],
            'criticality' => 'normal',
        ]], 'save');

        self::assertSame(302, $component->response()->getStatusCode());
        $files = self::getContainer()->get(AttachmentRepository::class)->findByIssue($this->issue('MD-SPT-11'));
        self::assertSame(['trace.log'], array_map(static fn (Attachment $file): string => $file->getFilename(), $files));
    }

    public function testRequiredFieldsAreCheckedByTheForm(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test')->set('trackerId', $this->bugs->getId());

        // The test client lets the 422 of an invalid form through as the exception it comes from.
        try {
            $component->submitForm(['issue' => ['title' => '', 'description' => 'Neither title nor environment.']], 'save');
            self::fail('The form is invalid');
        } catch (UnprocessableEntityHttpException $e) {
            self::assertStringContainsString('issue.title: This value should not be blank.', $e->getMessage());
            self::assertStringContainsString('issue.fields.environment: This value should not be blank.', $e->getMessage());
        }
        self::assertNull(self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-11'));
    }

    public function testTheDescriptionMayBeLeftEmpty(): void
    {
        $component = $this->form('nadia.benali@cairn.test')->set('trackerId', $this->tasks->getId());

        $component->submitForm(['issue' => ['title' => 'Purge the expired sessions', 'description' => '']], 'save');

        self::assertSame(302, $component->response()->getStatusCode(), $component->response()->getContent() ?: '');
        self::assertSame('', $this->issue('MD-SPT-11')->getDescription());
    }

    public function testChangingTheTrackerKeepsWhatWasWritten(): void
    {
        $component = $this->form('nadia.benali@cairn.test')->set('trackerId', $this->bugs->getId());
        $component
            ->set('issue.title', 'Purge the expired sessions')
            ->set('issue.description', 'Every night.')
            ->set('issue.criticality', 'high')
            ->set('issue.fields.environment', 'prod');

        $rendered = (string) $component->set('trackerId', $this->tasks->getId())->render();

        self::assertStringContainsString('value="Purge the expired sessions"', $rendered, 'the page shows what the component holds');
        self::assertStringContainsString('Every night.', $rendered);
        self::assertStringNotContainsString('issue[fields][environment]', $rendered, 'the fields are those of the new workflow');
        self::assertStringNotContainsString('This value should not be blank.', $rendered, 'nothing is judged yet');

        $component->call('save');

        self::assertSame(302, $component->response()->getStatusCode(), $component->response()->getContent() ?: '');
        $issue = $this->issue('MD-SPT-11');
        self::assertSame('Purge the expired sessions', $issue->getTitle());
        self::assertSame('Every night.', $issue->getDescription());
        self::assertSame(Criticality::High, $issue->getCriticality());
        self::assertSame($this->tasks->getId(), $issue->getTracker()->getId());
    }

    public function testAnInternalUserQualifiesAtCreation(): void
    {
        $lea = $this->scene->user('lea.fontaine@cairn.test');
        $component = $this->form('nadia.benali@cairn.test')->set('trackerId', $this->tasks->getId());
        $rendered = (string) $component->render();
        self::assertStringContainsString('issue[assignee]', $rendered);
        self::assertStringNotContainsString('issue[private]', $rendered, 'a private tracker leaves no choice');
        self::assertStringContainsString('issue[private]', (string) $component->set('trackerId', $this->bugs->getId())->render(), 'offered on a public tracker');

        $component->set('trackerId', $this->tasks->getId())->submitForm(['issue' => [
            'title' => 'Purge the expired sessions',
            'description' => 'The cron no longer runs.',
            'assignee' => (string) $lea->getId(),
        ]], 'save');

        self::assertSame(302, $component->response()->getStatusCode(), $component->response()->getContent() ?: '');
        $issue = $this->issue('MD-SPT-11');
        self::assertSame('lea.fontaine@cairn.test', $issue->getAssignee()?->getEmail());
        self::assertTrue($issue->isPrivate(), 'private through its tracker');
        self::assertSame('new', $issue->getPlace());
    }

    public function testAnIssueIsCreatedForATeamOfTheProject(): void
    {
        $commands = self::getContainer()->get(CommandBus::class);
        $hosting = $commands->dispatch(new CreateTeam('Hosting', true));
        self::assertInstanceOf(Team::class, $hosting);
        $commands->dispatch(new GrantTeamAccess((int) $hosting->getId(), $this->scene->dupont->getId(), null, 'developer'));
        $component = $this->form('nadia.benali@cairn.test')->set('trackerId', $this->tasks->getId());

        $rendered = (string) $component->render();
        self::assertStringContainsString('<optgroup label="Teams">', $rendered, 'after the people, under their own heading');
        self::assertStringContainsString('<option value="team-'.$hosting->getId().'">Hosting</option>', $rendered);

        $component->submitForm(['issue' => [
            'title' => 'Renew the certificate',
            'description' => 'It expires on Friday.',
            'assignee' => 'team-'.$hosting->getId(),
        ]], 'save');

        self::assertSame(302, $component->response()->getStatusCode(), $component->response()->getContent() ?: '');
        $issue = $this->issue('MD-SPT-11');
        self::assertSame('Hosting', $issue->getAssigneeTeam()?->getName());
        self::assertNull($issue->getAssignee());
    }

    public function testATrackerTheViewerMayNotUseIsIgnored(): void
    {
        $component = $this->form('claire.martin@maison-dupont.test')->set('trackerId', $this->tasks->getId());

        self::assertStringNotContainsString('issue[title]', (string) $component->render(), 'a private tracker is no choice for a client');

        $component->submitForm(['issue' => ['title' => 'Hidden', 'description' => 'x']], 'save');
        self::assertSame(200, $component->response()->getStatusCode(), 'nothing happens');
        self::assertNull(self::getContainer()->get(IssueRepository::class)->findOneByKey('MD-SPT-11'));
    }

    private function form(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:CreateForm', ['project' => $this->shop])->actingAs($this->scene->user($email));
    }

    private function issue(string $key): Issue
    {
        $issue = self::getContainer()->get(IssueRepository::class)->findOneByKey($key);
        self::assertNotNull($issue);

        return $issue;
    }
}
