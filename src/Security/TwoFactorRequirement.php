<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Who the instance holds to a second factor (`CAIRN_TWO_FACTOR`): nobody, the internal users, or everybody.
 */
enum TwoFactorRequirement: string
{
    case Optional = 'optional';
    case Internal = 'internal';
    case All = 'all';
}
