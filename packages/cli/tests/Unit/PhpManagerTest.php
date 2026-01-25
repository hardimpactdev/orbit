<?php

namespace Tests\Unit;

use App\Services\PhpManager;
use Mockery;
use Tests\TestCase;

class PhpManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_normalizes_php_version(): void
    {
        $phpManager = app(PhpManager::class);

        $this->assertEquals('84', $phpManager->normalizeVersion('8.4'));
        $this->assertEquals('84', $phpManager->normalizeVersion('84'));
        $this->assertEquals('83', $phpManager->normalizeVersion('8.3'));
    }

    public function test_gets_socket_path(): void
    {
        $phpManager = app(PhpManager::class);

        $socketPath = $phpManager->getSocketPath('8.4');
        $this->assertStringContainsString('php84.sock', $socketPath);
        $this->assertStringContainsString('.config/orbit/php', $socketPath);
    }

    public function test_gets_php_binary_path(): void
    {
        $phpManager = app(PhpManager::class);

        $binaryPath = $phpManager->getPhpBinaryPath('8.4');
        // Should contain php and version in some form
        $this->assertStringContainsString('php', $binaryPath);
    }

    public function test_gets_adapter(): void
    {
        $phpManager = app(PhpManager::class);

        $adapter = $phpManager->getAdapter();
        $this->assertInstanceOf(\App\Services\Platform\PlatformAdapter::class, $adapter);
    }
}
