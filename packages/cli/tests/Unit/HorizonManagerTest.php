<?php

namespace Tests\Unit;

use App\Services\HorizonManager;
use Tests\TestCase;

class HorizonManagerTest extends TestCase
{
    public function test_can_check_if_installed(): void
    {
        $manager = app(HorizonManager::class);

        // Just verify the method exists and returns a boolean
        $isInstalled = $manager->isInstalled();
        $this->assertIsBool($isInstalled);
    }

    public function test_can_check_if_running(): void
    {
        $manager = app(HorizonManager::class);

        // Just verify the method exists and returns a boolean
        $isRunning = $manager->isRunning();
        $this->assertIsBool($isRunning);
    }
}
