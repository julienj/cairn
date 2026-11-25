<?php

declare(strict_types=1);

namespace App\Entity\Enum;

/**
 * What the thread of an issue is made of, as someone filters it: the
 * changes of the issue and what a forge reported. The comments are the
 * thread itself and are never filtered.
 */
enum ActivityKind: string
{
    case Changes = 'changes';
    case Code = 'code';
}
