<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What the thread of an issue is made of, as someone filters it: the
 * changes of the issue, what a forge reported, and the comments.
 */
enum ActivityKind: string
{
    case Changes = 'changes';
    case Code = 'code';
    case Comments = 'comments';
}
