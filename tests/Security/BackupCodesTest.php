<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\BackupCodes;
use PHPUnit\Framework\TestCase;

final class BackupCodesTest extends TestCase
{
    public function testTenDistinctCodesAreDrawnInGroupsOfFour(): void
    {
        $codes = BackupCodes::generate();

        self::assertCount(BackupCodes::COUNT, $codes);
        self::assertCount(BackupCodes::COUNT, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/', $code);
        }
    }

    public function testACodeIsReadAsItIsShownWhateverTheDashesTheSpacesAndTheCase(): void
    {
        $digest = BackupCodes::digest('3fa9-81c2-d07e-55b1');

        self::assertSame($digest, BackupCodes::digest(' 3FA9 81C2 d07e-55B1 '));
        self::assertSame($digest, BackupCodes::digest('3fa981c2d07e55b1'));
        self::assertNotSame($digest, BackupCodes::digest('3fa9-81c2-d07e-55b2'));
        self::assertSame([$digest], BackupCodes::digests(['3fa9-81c2-d07e-55b1']));
        self::assertStringNotContainsString('3fa9', $digest, 'only the digest is kept');
    }
}
