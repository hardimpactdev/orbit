<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\Attributes\AfterClass;

trait WithSharedGitFixtureCleanup
{
    #[AfterClass]
    public static function clearSharedGitFixtures(): void
    {
        GatewayReleaseFixture::forget();
        LocalInstanceTransferTransport::forget();
    }
}
