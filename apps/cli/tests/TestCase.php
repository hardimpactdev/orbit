<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Extensions\GatewayExtensionState;
use App\Support\Console\StandardInputReader;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeStandardInput;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        GatewayExtensionState::reset();
        // The test runner's own standard input is unpredictable; a command that reads it gets an empty pipe.
        $this->app->instance(StandardInputReader::class, FakeStandardInput::piped(''));
    }
}
