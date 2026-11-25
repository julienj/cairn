<?php

declare(strict_types=1);

namespace App\Tests\Attachment;

use App\Attachment\AttachmentPruner;
use App\Attachment\AttachmentStorage;
use App\Entity\Attachment;
use App\Message\Command\Attachment\UploadAttachment;
use App\Message\Command\Issue\AddComment;
use App\Message\CommandBus;
use App\Repository\AttachmentRepository;
use App\Tests\KernelHelpers;
use App\Tests\MessageHandler\Command\Issue\IssueFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class AttachmentPrunerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use KernelHelpers;

    private CommandBus $commands;
    private IssueFixtures $fixtures;
    private AttachmentRepository $attachments;

    protected function setUp(): void
    {
        self::bootKernel();
        self::applyConfiguration();
        $this->commands = self::commandBus();
        $this->fixtures = new IssueFixtures(self::getContainer()->get(EntityManagerInterface::class), $this->commands);
        $this->attachments = self::getContainer()->get(AttachmentRepository::class);
    }

    public function testAbandonedUploadsAndOrphanFilesGoAfterADay(): void
    {
        $clock = self::mockTime('2026-11-01 10:00:00');
        self::assertInstanceOf(MockClock::class, $clock);
        $old = $this->upload('old.pdf');
        $clock->modify('+20 hours');
        $recent = $this->upload('recent.pdf');
        $issue = $this->fixtures->createIssue($this->fixtures->client);
        $taken = $this->upload('taken.pdf');
        $this->commands->dispatch(new AddComment((int) $this->fixtures->client->getId(), $issue->getKey(), 'Taken.', attachments: [$taken->getUuid()->toRfc4122()]));
        $storage = self::getContainer()->get(AttachmentStorage::class);
        self::assertSame(substr($old->getUuid()->toRfc4122(), 0, 2), basename(\dirname($storage->path($old))), 'a file lives in the folder of its first two characters');
        $orphan = $storage->pathOf(Uuid::v4());
        if (!is_dir(\dirname($orphan))) {
            mkdir(\dirname($orphan), recursive: true);
        }
        file_put_contents($orphan, 'orphan');
        touch($orphan, $clock->now()->getTimestamp() - 3 * 86400);
        $clock->modify('+6 hours');

        $pruner = self::getContainer()->get(AttachmentPruner::class);
        $preview = $pruner->prune(dryRun: true);
        self::assertSame([$old->getUuid()->toRfc4122()], $preview['rows']);
        self::assertContains(basename($orphan), $preview['files']);
        self::assertFileExists($storage->path($old), 'a dry run keeps everything');

        $result = $pruner->prune();

        self::assertSame([$old->getUuid()->toRfc4122()], $result['rows']);
        self::assertNull($this->attachments->findOneByUuid($old->getUuid()));
        self::assertFileDoesNotExist($storage->path($old));
        self::assertFileDoesNotExist($orphan);
        self::assertNotNull($this->attachments->findOneByUuid($recent->getUuid()), 'younger than a day');
        self::assertNotNull($this->attachments->findOneByUuid($taken->getUuid()), 'taken by a comment');
        self::assertSame([], $result['missing']);
    }

    private function upload(string $name): Attachment
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'cairn');
        file_put_contents($path, 'content of '.$name);
        $attachment = $this->commands->dispatch(new UploadAttachment((int) $this->fixtures->client->getId(), (int) $this->fixtures->bugs->getProject()->getId(), new UploadedFile($path, $name, null, null, true)));
        self::assertInstanceOf(Attachment::class, $attachment);

        return $attachment;
    }
}
