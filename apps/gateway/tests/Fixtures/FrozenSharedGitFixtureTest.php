<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use Tests\Support\GatewayReleaseFixture;
use Tests\Support\GuardsSudo;
use Tests\Support\WithSharedGitFixtureCleanup;

/** Exercises stock class cleanup without booting a nested Laravel application. */
final class FrozenSharedGitFixtureTest extends TestCase
{
    use GuardsSudo;
    use WithSharedGitFixtureCleanup;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        GatewayReleaseFixture::prepareAdopted();
    }

    public function test_leaves_real_frozen_release_seeds_for_class_cleanup(): void
    {
        $fixture = GatewayReleaseFixture::create(adopted: true);
        $outside = $fixture->base.'-linked-file';
        try {
            $release = realpath($fixture->layout->currentPath());
            self::assertIsString($release);
            self::assertSame(0, fileperms($release.'/apps/gateway') & 0o200);
            self::assertSame(0, fileperms($release.'/.git') & 0o200);
            file_put_contents($outside, 'keep these bytes');
            chmod($outside, 0o444);
            mkdir($fixture->base.'/traversal-only', 0o700);
            link($outside, $fixture->base.'/traversal-only/file');
            chmod($fixture->base.'/traversal-only', 0o100);
            $fixture->cleanup();
            clearstatcache();
            self::assertDirectoryDoesNotExist($fixture->base);
            self::assertSame(0o444, fileperms($outside) & 0o777);
            self::assertSame('keep these bytes', file_get_contents($outside));
        } finally {
            $fixture->cleanup();
            if (is_file($outside)) {
                unlink($outside);
            }
        }
    }
}
