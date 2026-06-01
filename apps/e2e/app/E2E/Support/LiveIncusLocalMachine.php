<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class LiveIncusLocalMachine
{
    public function hasWireGuardTools(): bool
    {
        return Process::timeout(5)
            ->run('command -v wg >/dev/null 2>&1 && command -v wg-quick >/dev/null 2>&1')
            ->successful();
    }

    /**
     * @return list<string>
     */
    public function wireGuardInterfaces(): array
    {
        $result = Process::timeout(10)->run(['wg', 'show', 'interfaces']);

        if (! $result->successful()) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($result->output())) ?: []));
    }

    public function realWireGuardInterface(string $interface): ?string
    {
        $namePath = "/var/run/wireguard/{$interface}.name";

        if (! is_file($namePath)) {
            return in_array($interface, $this->wireGuardInterfaces(), true) ? $interface : null;
        }

        $realInterface = trim((string) @file_get_contents($namePath));

        return $realInterface !== '' ? $realInterface : null;
    }

    public function startWireGuard(string $configPath): ProcessResult
    {
        return Process::timeout(180)
            ->tty($this->hasTty())
            ->run(['sudo', 'wg-quick', 'up', $configPath]);
    }

    public function stopWireGuard(string $configPath): ProcessResult
    {
        return Process::timeout(180)
            ->tty($this->hasTty())
            ->run(['sudo', 'wg-quick', 'down', $configPath]);
    }

    public function addGateway(string $gatewayIp, string $gatewayName): ProcessResult
    {
        return Process::timeout(120)->run([repo_path('bin/orbit'), 'gateway:add', $gatewayIp, "--name={$gatewayName}", '--json']);
    }

    public function verifyGateway(string $gatewayIp): ProcessResult
    {
        return Process::timeout(30)->run(['curl', '--fail', '--silent', '--show-error', '--max-time', '10', "http://{$gatewayIp}/api/ca/root"]);
    }

    private function hasTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }
}
