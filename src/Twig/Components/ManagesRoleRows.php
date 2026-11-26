<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * For the live components that list accesses, one row each: the role of a
 * row is a select bound to `roles`, and a row is taken back after a
 * confirmation in place (`revoking`, drawn by
 * `components/_revoke_confirm.html.twig`). The component says how a role
 * is changed and an access taken back, and reads its rows again in
 * `align()`. Needs ComponentToolsTrait, a `config` and a `translator`.
 */
trait ManagesRoleRows
{
    /** @var array<int, string> access id => role, bound to the role of each row */
    #[LiveProp(writable: true)]
    public array $roles = [];

    /** The access whose removal waits for a confirmation. */
    #[LiveProp(writable: true)]
    public ?int $revoking = null;

    #[LiveAction]
    public function confirmRevoke(#[LiveArg] int $access): void
    {
        $this->revoking = $access;
    }

    #[LiveAction]
    public function cancelRevoke(): void
    {
        $this->revoking = null;
    }

    /**
     * The label of a role, or its id when the configuration dropped it.
     */
    public function roleLabel(string $role): string
    {
        $definition = $this->config->definition();

        return $definition->hasRole($role) ? $definition->role($role)->label : $role;
    }

    /**
     * Aligns the role of each row with the database, read again.
     */
    abstract private function align(): void;

    private function done(string $message): void
    {
        $this->align();
        $this->dispatchBrowserEvent('toast:show', ['message' => $this->translator->trans($message)]);
    }
}
