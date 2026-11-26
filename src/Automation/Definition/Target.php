<?php

declare(strict_types=1);

namespace App\Automation\Definition;

/**
 * Who an action of a rule aims at, written so that the rule holds on any
 * project: the referent of a role, someone the issue itself names, or a
 * team, which is the same on every project. Only `user:<id>` names a
 * person, and so belongs to one project. Stored as a string:
 * `referent:project_manager`, `author`, `team:3`, `user:12`.
 */
final readonly class Target
{
    public const string REFERENT = 'referent';
    public const string AUTHOR = 'author';
    public const string LAST_INTERNAL = 'last_internal';
    public const string NOBODY = 'nobody';
    public const string USER = 'user';
    public const string TEAM = 'team';

    /** The targets read from the issue, which need no argument. */
    public const array RELATIVE = [self::AUTHOR, self::LAST_INTERNAL];

    private function __construct(
        public string $kind,
        public string $argument = '',
    ) {
    }

    public static function parse(mixed $value): ?self
    {
        if (!\is_string($value)) {
            return null;
        }
        [$kind, $argument] = explode(':', $value, 2) + [1 => ''];

        return match (true) {
            self::REFERENT === $kind && '' !== $argument,
            self::USER === $kind && ctype_digit($argument),
            self::TEAM === $kind && ctype_digit($argument),
            \in_array($kind, [...self::RELATIVE, self::NOBODY], true) && '' === $argument => new self($kind, $argument),
            default => null,
        };
    }

    public static function referent(string $role): string
    {
        return self::REFERENT.':'.$role;
    }

    public static function user(int $id): string
    {
        return self::USER.':'.$id;
    }

    public static function team(int $id): string
    {
        return self::TEAM.':'.$id;
    }
}
