<?php

namespace Tests\Unit;

use App\Services\Platform\LinuxAdapter;
use App\Services\Platform\MacAdapter;
use Tests\TestCase;

class PlatformAdapterTest extends TestCase
{
    public function test_linux_adapter_returns_socket_path(): void
    {
        $adapter = new LinuxAdapter;
        $socketPath = $adapter->getSocketPath('8.4');

        $this->assertStringContainsString('php84.sock', $socketPath);
    }

    public function test_linux_adapter_returns_php_binary_path(): void
    {
        $adapter = new LinuxAdapter;
        $binaryPath = $adapter->getPhpBinaryPath('8.4');

        $this->assertStringContainsString('/usr/bin/php8.4', $binaryPath);
    }

    public function test_mac_adapter_returns_socket_path(): void
    {
        $adapter = new MacAdapter;
        $socketPath = $adapter->getSocketPath('8.4');

        $this->assertStringContainsString('php84.sock', $socketPath);
    }

    public function test_mac_adapter_returns_php_binary_path(): void
    {
        $adapter = new MacAdapter;
        $binaryPath = $adapter->getPhpBinaryPath('8.4');

        // Should contain homebrew path or php version
        $this->assertTrue(
            str_contains($binaryPath, '/opt/homebrew') ||
            str_contains($binaryPath, 'php')
        );
    }
}
