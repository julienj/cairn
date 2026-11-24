<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * How the changes of the issues someone follows reach them by mail: one
 * mail per project, with every issue that changed there, one mail per
 * issue, or no mail at all. The wait before a mail leaves is the same
 * either way (Notification/ProjectMailSender); only what shares a mail
 * changes. Without mail, nothing waits for them and nothing that waited
 * leaves; they still follow the issues.
 */
enum MailGrouping: string
{
    case Project = 'project';
    case Issue = 'issue';
    case None = 'none';

    public function sendsMails(): bool
    {
        return self::None !== $this;
    }
}
