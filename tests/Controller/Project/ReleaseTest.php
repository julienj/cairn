<?php

declare(strict_types=1);

namespace App\Tests\Controller\Project;

use App\Entity\Enum\ReleaseStatus;
use App\Entity\Release;
use App\Repository\ReleaseRepository;
use App\Tests\Scene\ShopScene;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The releases of the shop: Thomas manages Maison Dupont, Nadia develops
 * on the shop, Claire is its client. MD-SPT-6 is an internal task.
 */
final class ReleaseTest extends WebTestCase
{
    private KernelBrowser $client;
    private ShopScene $scene;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->scene = ShopScene::load(self::getContainer());
    }

    public function testThePageIsInTheNavigationOnceTheProjectHasReleases(): void
    {
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('a[href="/MD/SPT/releases"]');

        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('a[href="/MD/SPT/releases"]', 'whoever may create the first one finds the page');

        $this->scene->release();
        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorExists('a[href="/MD/SPT/releases"]');
    }

    public function testAClientReadsTheReleasesWithoutTheInternalIssues(): void
    {
        $release = $this->scene->release('2.5.0', ['MD-SPT-3', 'MD-SPT-5', 'MD-SPT-6']);

        $this->signInAs('claire.martin@maison-dupont.test');
        $this->client->request('GET', '/MD/SPT/releases');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '2.5.0');
        self::assertSelectorTextContains('main', 'Done: 1 of 2', 'the bar counts what is finished, out of what the client sees');
        self::assertSelectorTextContains('main', '50 %');
        self::assertSelectorTextContains('main a[href*="places"]', 'In progress', 'what is left is told by its step');
        self::assertSelectorExists(\sprintf('main a[href="/MD/SPT/issues?releases%%5B0%%5D=%d&places%%5B0%%5D=in_progress"]', $release->getId()), 'which leads to those issues');
        self::assertSelectorTextContains('main', '1 to finish before delivery');
        self::assertSelectorNotExists('a[href="/MD/SPT/releases/new"]');

        $this->client->request('GET', '/MD/SPT/releases/new');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/MD/SPT/releases/new', ['release' => ['name' => '2.6.0']]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheManagerCreatesARelease(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/releases');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[role="dialog"]');
        $this->client->clickLink('New release');
        self::assertRouteSame('app_project_release_new');
        self::assertSelectorExists('turbo-frame#modal form[action="/MD/SPT/releases/new"]', 'loaded over the list, the form is still sent to the dialog');

        $this->client->submitForm('Create the release', ['release[name]' => ' '], 'POST');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="dialog"]', 'the dialog stays open on the refusal');

        $this->client->submitForm('Create the release', ['release[name]' => '2.5.0', 'release[plannedOn]' => '2026-11-20', 'release[description]' => 'Checkout.']);
        self::assertResponseRedirects('/MD/SPT/releases');
        $release = $this->release('2.5.0');
        self::assertSame('2026-11-20', $release->getPlannedOn()?->format('Y-m-d'));

        $this->client->followRedirect();
        self::assertSelectorNotExists('[role="dialog"]');
        $this->client->clickLink('New release');
        $this->client->submitForm('Create the release', ['release[name]' => '2.5.0']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form[name="release"]', 'already has a release named 2.5.0');
    }

    public function testTheManagerEditsAndDeliversARelease(): void
    {
        $release = $this->scene->release();
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', \sprintf('/MD/SPT/releases/%d', $release->getId()));
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save', ['release[name]' => '2.5.1', 'release[plannedOn]' => '']);
        self::assertResponseRedirects('/MD/SPT/releases');
        self::assertNull($this->release('2.5.1')->getPlannedOn());

        $this->client->followRedirect();
        $this->client->submitForm('Mark as delivered');
        self::assertResponseRedirects('/MD/SPT/releases');
        self::assertSame(ReleaseStatus::Delivered, $this->release('2.5.1')->getStatus());
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Delivered and abandoned');
    }

    public function testTheManagerAbandonsAReleaseFromItsDialog(): void
    {
        $release = $this->scene->release();
        $this->signInAs('thomas.girard@cairn.test');

        $crawler = $this->client->request('GET', '/MD/SPT/releases');
        $this->client->click($crawler->filter('main a[aria-label="Edit"]')->link());
        self::assertRouteSame('app_project_release_edit');
        self::assertSelectorTextContains('[role="dialog"]', $release->getName());
        self::assertSelectorExists(\sprintf('turbo-frame#modal form[name="release"][action="/MD/SPT/releases/%d"]', $release->getId()));

        $this->client->submitForm('Abandon the release');
        self::assertResponseRedirects('/MD/SPT/releases');
        self::assertSame(ReleaseStatus::Abandoned, $this->release($release->getName())->getStatus());
    }

    public function testTheManagerDeletesAReleaseThatHoldsIssuesFromItsDialog(): void
    {
        $release = $this->scene->release('2.5.0', ['MD-SPT-1', 'MD-SPT-2']);
        $this->signInAs('thomas.girard@cairn.test');

        $this->client->request('GET', \sprintf('/MD/SPT/releases/%d', $release->getId()));
        self::assertSelectorTextContains('[role="dialog"]', 'the 2 issues it holds will have no release');

        $this->client->submitForm('Delete the release');
        self::assertResponseRedirects('/MD/SPT/releases');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Release 2.5.0 deleted.');
        self::assertNull(self::getContainer()->get(ReleaseRepository::class)->findOneByName($this->scene->shop, '2.5.0'));

        $this->client->request('GET', '/MD/SPT/issues/1');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', '2.5.0', 'the history keeps the name of the release');
    }

    public function testTheDialogOfAReleaseIsAnsweredAloneToItsFrame(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $frame = ['HTTP_TURBO_FRAME' => 'modal'];

        $this->client->request('GET', '/MD/SPT/releases/new', [], [], $frame);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal form[name="release"]');
        self::assertSelectorNotExists('nav', 'nothing of the page behind the dialog');

        $this->client->submitForm('Create the release', ['release[name]' => ' '], 'POST', $frame);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#modal [role="dialog"]', 'refused, the dialog alone is drawn again');
        self::assertSelectorNotExists('nav');

        $this->client->submitForm('Create the release', ['release[name]' => '2.5.0'], 'POST', $frame);
        self::assertResponseRedirects('/MD/SPT/releases');
        $this->client->request('GET', '/MD/SPT/releases', [], [], $frame);
        self::assertSelectorNotExists('turbo-frame#modal *', 'the frame is answered empty, which closes the dialog');
        self::assertSelectorNotExists('nav');

        $this->client->request('GET', '/MD/SPT/releases');
        self::assertSelectorTextContains('body', '2.5.0', 'the visit that follows shows the release');
        self::assertSelectorTextContains('body', 'Release 2.5.0 created.', 'and the flash the frame left unread');
    }

    public function testADeveloperNeitherEditsNorDelivers(): void
    {
        $release = $this->scene->release();
        $this->signInAs('nadia.benali@cairn.test');

        $this->client->request('GET', \sprintf('/MD/SPT/releases/%d', $release->getId()));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', \sprintf('/MD/SPT/releases/%d/status', $release->getId()), ['status' => 'delivered']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheListOfTheProjectFiltersAndGroupsByRelease(): void
    {
        $this->signInAs('thomas.girard@cairn.test');
        $this->client->request('GET', '/MD/SPT/issues');
        self::assertSelectorNotExists('[data-live-prop-name-param="releases"], input[data-model="releases"]');

        $release = $this->scene->release('2.5.0', ['MD-SPT-3']);
        $this->client->request('GET', \sprintf('/MD/SPT/issues?releases[]=%d', $release->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'MD-SPT-3');
        self::assertSelectorTextNotContains('main', 'MD-SPT-1 ');
        self::assertSelectorTextContains('main', '1 issue');
    }

    private function release(string $name): Release
    {
        $release = self::getContainer()->get(ReleaseRepository::class)->findOneByName($this->scene->shop, $name);
        self::assertInstanceOf(Release::class, $release);

        return $release;
    }

    private function signInAs(string $email): void
    {
        $this->client->loginUser($this->scene->user($email));
    }
}
