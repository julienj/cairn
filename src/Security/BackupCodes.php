<?php

declare(strict_types=1);

namespace App\Security;

/**
 * The backup codes of an account: drawn here, shown once, kept as digests. A code carries 64 bits, so an
 * unsalted digest is enough: nobody guesses it from the database.
 */
final class BackupCodes
{
    public const int COUNT = 10;

    /**
     * @return list<string> like "3fa9-81c2-d07e-55b1"
     */
    public static function generate(): array
    {
        $codes = [];
        for ($i = 0; $i < self::COUNT; ++$i) {
            $codes[] = implode('-', str_split(bin2hex(random_bytes(8)), 4));
        }

        return $codes;
    }

    /**
     * What is typed is read as is shown, whatever the dashes, spaces and case.
     */
    public static function digest(string $code): string
    {
        return hash('sha256', strtolower((string) preg_replace('/[\s-]+/', '', $code)));
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    public static function digests(array $codes): array
    {
        return array_map(self::digest(...), $codes);
    }
}
