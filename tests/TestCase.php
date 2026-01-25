<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Vite;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Disable Vite for testing - we don't need actual assets
        Vite::useScriptTagAttributes(['type' => 'module']);
        $this->withoutVite();
    }
}
