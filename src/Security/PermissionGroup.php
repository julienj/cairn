<?php

declare(strict_types=1);

namespace App\Security;

/**
 * What the permissions are about, for the page that grants them: its
 * rows come group by group, in this order, each permission in the order
 * of its enum. Only a way to read them: nothing is granted by group.
 */
enum PermissionGroup: string
{
    case Issues = 'issues';
    case Tracking = 'tracking';
    case Comments = 'comments';
    case Project = 'project';
    case Configuration = 'configuration';
    case Budgets = 'budgets';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values(array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->group() === $this));
    }
}
