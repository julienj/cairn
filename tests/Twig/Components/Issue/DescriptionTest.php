<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components\Issue;

use App\Entity\Issue;
use App\Tests\Scene\ShopScene;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * The description of MD-SPT-4, which Claire wrote; Nadia may edit the issue
 * but did not write it.
 */
final class DescriptionTest extends KernelTestCase
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

    public function testTheAuthorRewritesTheDescriptionInPlace(): void
    {
        $component = $this->description('claire.martin@maison-dupont.test');

        $component->call('editDescription')->set('description', 'The file only holds the header.')->call('saveDescription');
        $render = $component->render();

        self::assertStringContainsString('<p>The file only holds the header.</p>', (string) $render);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame('The file only holds the header.', $entityManager->find(Issue::class, $this->issue->getId())?->getDescription());
        self::assertNotNull($component->getEmittedEvent($render, 'issue:changed'));
    }

    public function testTheDescriptionIsEmptiedAndThePageSaysThereIsNone(): void
    {
        $component = $this->description('claire.martin@maison-dupont.test');

        $component->call('editDescription')->set('description', '')->call('saveDescription');

        self::assertStringContainsString('No description.', (string) $component->render());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertSame('', $entityManager->find(Issue::class, $this->issue->getId())?->getDescription());
    }

    public function testTheAuthorOrAManagerRewritesTheDescription(): void
    {
        self::assertStringContainsString('data-live-action-param="editDescription"', (string) $this->description('claire.martin@maison-dupont.test')->render());
        self::assertStringContainsString('data-live-action-param="editDescription"', (string) $this->description('thomas.girard@cairn.test')->render(), 'issue.edit');

        $developer = $this->description('nadia.benali@cairn.test');
        self::assertStringNotContainsString('data-live-action-param="editDescription"', (string) $developer->render(), 'Nadia did not write it and may not edit the issues of others');
        $developer->call('editDescription');
        self::assertStringNotContainsString('saveDescription', (string) $developer->render(), 'and the editor does not open for her');
    }

    private function description(string $email): TestLiveComponent
    {
        return $this->createLiveComponent('Issue:Description', ['issue' => $this->issue])->actingAs($this->scene->user($email));
    }
}
