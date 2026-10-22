<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;
use Tests\Support\FeatureTestEnvironment;
use Tests\Support\GuardsSudo;

/** Native PHPUnit Feature tests use the same isolation as Pest's Feature hooks. */
abstract class FeatureTestCase extends TestCase
{
    use GuardsSudo;
    use LazilyRefreshDatabase;
    use WithCachedConfig;
    use WithCachedRoutes;

    private FeatureTestEnvironment $featureEnvironment;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->featureEnvironment = FeatureTestEnvironment::start();
    }

    #[\Override]
    protected function tearDown(): void
    {
        try {
            $this->featureEnvironment->finish();
        } finally {
            parent::tearDown();
        }
    }
}
