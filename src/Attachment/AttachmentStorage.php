<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Entity\Attachment;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Uid\Uuid;

/**
 * Where attachment files live: `ATTACHMENTS_DIR/files`, outside
 * `public/` and beside the pictures of the accounts (AvatarStorage), one
 * file per attachment named by its uuid, without extension, in a folder
 * named by the first two characters of that uuid (`files/3f/3f2a…`): 256
 * folders share the files evenly, and the uuid alone says where a file
 * is, its row gone or not. Nothing else knows the layout.
 */
final class AttachmentStorage
{
    public function __construct(
        #[Autowire('%env(resolve:ATTACHMENTS_DIR)%/files')]
        private readonly string $directory,
    ) {
    }

    /**
     * Moves the file in: one a user sent, or one an import took out of its archive.
     */
    public function store(File $file, Attachment $attachment): void
    {
        $path = $this->path($attachment);
        $file->move(\dirname($path), basename($path));
    }

    public function delete(Attachment $attachment): void
    {
        $this->remove($attachment->getUuid());
    }

    /**
     * The file of an attachment whose row is already gone.
     */
    public function remove(Uuid $uuid): void
    {
        $path = $this->pathOf($uuid);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function path(Attachment $attachment): string
    {
        return $this->pathOf($attachment->getUuid());
    }

    public function pathOf(Uuid $uuid): string
    {
        $name = $uuid->toRfc4122();

        return $this->directory.'/'.substr($name, 0, 2).'/'.$name;
    }

    /**
     * The uuids of the files present, whatever rows exist for them, read
     * a folder at a time. A file that is not where its name puts it is
     * not one of ours.
     *
     * @return iterable<Uuid>
     */
    public function storedUuids(): iterable
    {
        foreach (glob($this->directory.'/[0-9a-f][0-9a-f]', \GLOB_ONLYDIR) ?: [] as $folder) {
            foreach (glob($folder.'/*') ?: [] as $path) {
                $name = basename($path);
                if (Uuid::isValid($name) && $this->pathOf(Uuid::fromString($name)) === $path) {
                    yield Uuid::fromString($name);
                }
            }
        }
    }

    /**
     * The file as an answer, for whoever the caller found may see it:
     * images are shown, anything else, HTML or SVG included, is only
     * downloaded.
     */
    public function serve(Attachment $attachment): BinaryFileResponse
    {
        $image = $attachment->isImage();
        $response = new BinaryFileResponse($this->path($attachment), headers: [
            'Content-Type' => $image ? $attachment->getMediaType() : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setContentDisposition($image ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getFilename());
        $response->setAutoLastModified();
        $response->setPrivate();
        $response->setMaxAge(3600);

        return $response;
    }
}
