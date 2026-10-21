<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Extensions\GatewayExtensionState;
use Illuminate\Foundation\Testing\WithCachedConfig;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use WithCachedConfig;

    protected function setUp(): void
    {
        parent::setUp();

        GatewayExtensionState::reset();
    }
}
