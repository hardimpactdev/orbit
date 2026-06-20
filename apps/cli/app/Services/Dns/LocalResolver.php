<?php

declare(strict_types=1);

namespace App\Services\Dns;

use Illuminate\Contracts\Process\ProcessResult as ProcessResultContract;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class LocalResolver implements ResolvesLocalDns
{
    private ?string $overridePlatform = null;

    public function setPlatform(string $platform): void
    {
        $this->overridePlatform = $platform;
    }

    public function platform(): string
    {
        if ($this->overridePlatform !== null) {
            return $this->overridePlatform;
        }

        return match (PHP_OS_FAMILY) {
            'Darwin' => 'macos',
            'Linux' => 'linux',
            default => 'unsupported',
        };
    }

    public function isSupported(): bool
    {
        return in_array($this->platform(), ['linux', 'macos'], true);
    }

    public function supportsMutation(): bool
    {
        return $this->platform() === 'macos';
    }

    public function backend(): string
    {
        return 'dnsmasq';
    }

    public function configDir(): string
    {
        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            throw new \RuntimeException('HOME environment variable is not set.');
        }

        return rtrim($home, '/').'/.config/orbit/dnsmasq.d';
    }

    public function isDnsmasqInstalled(): bool
    {
        $result = Process::timeout(10)->run('which dnsmasq');

        if ($result->successful()) {
            return true;
        }

        if ($this->platform() !== 'macos') {
            return false;
        }

        $prefixResult = Process::timeout(10)->run('brew --prefix');

        if (! $prefixResult->successful()) {
            return false;
        }

        $prefix = trim($prefixResult->output());

        return is_executable("{$prefix}/sbin/dnsmasq")
            || is_executable("{$prefix}/bin/dnsmasq");
    }

    public function existingTarget(string $tld): ?string
    {
        $configPath = $this->configPath($tld);

        if (! File::exists($configPath)) {
            return null;
        }

        $content = File::get($configPath);

        if (preg_match('/^address=\/\.?'.preg_quote($tld, '/').'\/([^\r\n#]+)\s*$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * @return array<int, array{tld: string, target: string, source: string, resolver_backend: string, status: string}>
     */
    public function listOverrides(): array
    {
        $configDir = $this->configDir();

        if (! File::isDirectory($configDir)) {
            return [];
        }

        $overrides = [];

        foreach (File::files($configDir) as $file) {
            if ($file->getExtension() !== 'conf') {
                continue;
            }

            $tld = $file->getBasename('.conf');
            $target = $this->existingTarget($tld);

            if ($target === null) {
                continue;
            }

            $overrides[] = [
                'tld' => $tld,
                'target' => $target,
                'source' => 'local_resolver',
                'resolver_backend' => $this->backend(),
                'status' => 'active',
            ];
        }

        usort($overrides, fn (array $left, array $right): int => $left['tld'] <=> $right['tld']);

        return $overrides;
    }

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function resolve(string $tld, string $target): array
    {
        $existing = $this->existingTarget($tld);
        $configDir = $this->configDir();
        File::ensureDirectoryExists($configDir);

        $masterConfig = $this->masterConfigPath();
        $configChanged = $this->syncMasterConfig($masterConfig, $configDir, $tld);
        $systemResolverResult = $this->syncSystemResolver($tld);

        if (isset($systemResolverResult['error'])) {
            return ['status' => 'write_failed', 'changed' => $configChanged, 'error' => $systemResolverResult['error']];
        }

        $systemResolverChanged = $systemResolverResult['changed'];

        if ($existing === $target) {
            $healthError = $configChanged
                ? 'dnsmasq configuration changed.'
                : $this->verifyDnsmasqServesTarget($tld, $target, includeServiceDiagnostics: false);

            if ($healthError !== null) {
                $refreshError = $this->refreshDnsmasq($tld, $target);

                if ($refreshError !== null) {
                    return ['status' => 'refresh_failed', 'changed' => $configChanged || $systemResolverChanged, 'error' => $refreshError];
                }
            }

            if ($configChanged || $systemResolverChanged) {
                return ['status' => 'resolved', 'changed' => true];
            }

            return ['status' => 'already_resolved', 'changed' => false];
        }

        File::put($this->configPath($tld), "address=/{$tld}/{$target}\n");

        $resolverWriteCommand = "sudo -n mkdir -p /etc/resolver && echo 'nameserver 127.0.0.1' | sudo -n tee ".escapeshellarg("/etc/resolver/{$tld}").' > /dev/null';

        try {
            $resolverResult = Process::timeout(10)->run($resolverWriteCommand);
        } catch (ProcessTimedOutException $exception) {
            return ['status' => 'write_failed', 'changed' => false, 'error' => $this->formatTimeoutFailure($resolverWriteCommand, $exception)];
        }

        if (! $resolverResult->successful()) {
            return ['status' => 'write_failed', 'changed' => false, 'error' => $resolverResult->errorOutput()];
        }

        $refreshError = $this->refreshDnsmasq($tld, $target);

        if ($refreshError !== null) {
            return ['status' => 'refresh_failed', 'changed' => true, 'error' => $refreshError];
        }

        $this->flushMacOSResolverCache();

        return ['status' => 'resolved', 'changed' => true];
    }

    /**
     * @return array{status: string, changed: bool, error?: string}
     */
    public function reset(string $tld): array
    {
        $configPath = $this->configPath($tld);
        $hasConfig = File::exists($configPath);
        $hasResolver = Process::timeout(10)->run('test -f '.escapeshellarg("/etc/resolver/{$tld}"))->successful();

        if (! $hasConfig && ! $hasResolver) {
            return ['status' => 'already_absent', 'changed' => false];
        }

        if ($hasConfig) {
            File::delete($configPath);
        }

        if ($hasResolver) {
            $removeCommand = 'sudo -n rm '.escapeshellarg("/etc/resolver/{$tld}");

            try {
                $removeResult = Process::timeout(10)->run($removeCommand);
            } catch (ProcessTimedOutException $exception) {
                return ['status' => 'write_failed', 'changed' => true, 'error' => $this->formatTimeoutFailure($removeCommand, $exception)];
            }

            if (! $removeResult->successful()) {
                return ['status' => 'write_failed', 'changed' => true, 'error' => $removeResult->errorOutput()];
            }
        }

        $refreshError = $this->refreshDnsmasq();

        if ($refreshError !== null) {
            return ['status' => 'refresh_failed', 'changed' => true, 'error' => $refreshError];
        }

        $this->flushMacOSResolverCache();

        return ['status' => 'reset', 'changed' => true];
    }

    /**
     * @return array{changed: bool, error?: string}
     */
    private function syncSystemResolver(string $tld): array
    {
        if ($this->platform() !== 'macos') {
            return ['changed' => false];
        }

        if ($this->systemResolverUsesLocalDnsmasq($tld)) {
            return ['changed' => false];
        }

        $writeCommand = "sudo -n mkdir -p /etc/resolver && echo 'nameserver 127.0.0.1' | sudo -n tee ".escapeshellarg("/etc/resolver/{$tld}").' > /dev/null';

        try {
            $writeResult = Process::timeout(10)->run($writeCommand);
        } catch (ProcessTimedOutException $exception) {
            return [
                'changed' => false,
                'error' => $this->formatTimeoutFailure($writeCommand, $exception),
            ];
        }

        if (! $writeResult->successful()) {
            return [
                'changed' => false,
                'error' => trim($writeResult->errorOutput()) ?: "Failed to update /etc/resolver/{$tld}.",
            ];
        }

        $this->flushMacOSResolverCache();

        return ['changed' => true];
    }

    private function systemResolverUsesLocalDnsmasq(string $tld): bool
    {
        $result = Process::timeout(10)->run('cat '.escapeshellarg("/etc/resolver/{$tld}"));

        if (! $result->successful()) {
            return false;
        }

        return $this->resolverNameservers($result->output()) === ['127.0.0.1'];
    }

    /**
     * @return array<int, string>
     */
    private function resolverNameservers(string $contents): array
    {
        $nameservers = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^nameserver\s+(.+)$/', $line, $matches) === 1) {
                $nameservers[] = trim($matches[1]);
            }
        }

        return $nameservers;
    }

    private function flushMacOSResolverCache(): void
    {
        if ($this->platform() !== 'macos') {
            return;
        }

        Process::timeout(10)->run('dscacheutil -flushcache');
        Process::timeout(10)->run('sudo killall -HUP mDNSResponder');
    }

    private function configPath(string $tld): string
    {
        return $this->configDir()."/{$tld}.conf";
    }

    private function masterConfigPath(): string
    {
        $prefixResult = Process::timeout(10)->run('brew --prefix');
        $prefix = $prefixResult->successful() ? trim($prefixResult->output()) : '/opt/homebrew';

        return "{$prefix}/etc/dnsmasq.conf";
    }

    private function syncMasterConfig(string $masterConfig, string $configDir, string $tld): bool
    {
        File::ensureDirectoryExists(dirname($masterConfig));

        $confDirLine = "conf-dir={$configDir}/,*.conf";

        if (! File::exists($masterConfig)) {
            File::put($masterConfig, "{$confDirLine}\n");

            return true;
        }

        $contents = File::get($masterConfig);
        $lines = preg_split('/\R/', $contents) ?: [];
        $nextLines = [];

        foreach ($lines as $line) {
            if ($this->isOrbitDnsmasqConfDirLine($line) || $this->isDnsmasqAddressLineForTld($line, $tld)) {
                continue;
            }

            $nextLines[] = $line;
        }

        while ($nextLines !== [] && end($nextLines) === '') {
            array_pop($nextLines);
        }

        $nextLines[] = $confDirLine;

        $nextContents = implode("\n", $nextLines)."\n";

        if ($nextContents === $contents) {
            return false;
        }

        File::put($masterConfig, $nextContents);

        return true;
    }

    private function isOrbitDnsmasqConfDirLine(string $line): bool
    {
        return preg_match('#^conf-dir=.+/(?:\.config/orbit|orbit)/dnsmasq\.d/,\*\.conf$#', trim($line)) === 1;
    }

    private function isDnsmasqAddressLineForTld(string $line, string $tld): bool
    {
        return preg_match('/^address=\/\.?'.preg_quote($tld, '/').'\/.+$/', trim($line)) === 1;
    }

    private function refreshDnsmasq(?string $tld = null, ?string $target = null): ?string
    {
        try {
            $restartResult = Process::timeout(30)->run('sudo brew services restart dnsmasq');
        } catch (ProcessTimedOutException $exception) {
            return $this->formatTimeoutFailure('sudo brew services restart dnsmasq', $exception);
        }

        if (! $restartResult->successful()) {
            return trim($restartResult->errorOutput()) ?: 'Failed to restart dnsmasq with sudo brew services restart dnsmasq.';
        }

        if ($tld === null || $target === null) {
            $command = 'dig @127.0.0.1 localhost +short';

            try {
                $healthResult = Process::timeout(10)->run($command);
            } catch (ProcessTimedOutException $exception) {
                return $this->formatTimeoutFailure($command, $exception, includeServiceDiagnostics: true);
            }

            if ($healthResult->successful()) {
                return null;
            }

            return $this->formatDigFailure('localhost', null, $healthResult);
        }

        return $this->verifyDnsmasqServesTarget($tld, $target);
    }

    private function verifyDnsmasqServesTarget(string $tld, string $target, bool $includeServiceDiagnostics = true): ?string
    {
        $hostname = "orbit-local-resolver-health.{$tld}";
        $command = "dig @127.0.0.1 {$hostname} +short";

        try {
            $healthResult = Process::timeout(10)->run($command);
        } catch (ProcessTimedOutException $exception) {
            return $this->formatTimeoutFailure($command, $exception, $hostname, $target, $includeServiceDiagnostics);
        }

        if ($healthResult->successful() && in_array($target, $this->digAnswers($healthResult->output()), true)) {
            return null;
        }

        return $this->formatDigFailure($hostname, $target, $healthResult, $includeServiceDiagnostics);
    }

    private function formatTimeoutFailure(
        string $command,
        ProcessTimedOutException $exception,
        ?string $hostname = null,
        ?string $target = null,
        bool $includeServiceDiagnostics = false,
    ): string {
        if ($hostname !== null && $target !== null) {
            $message = "dnsmasq did not return {$target} for {$hostname}. Error: {$exception->getMessage()}";
        } else {
            $message = "Process timed out while running {$command}. Error: {$exception->getMessage()}";
        }

        if ($includeServiceDiagnostics) {
            return $this->withDnsmasqServiceDiagnostics($message);
        }

        return $message;
    }

    /**
     * @return array<int, string>
     */
    private function digAnswers(string $output): array
    {
        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R+/', $output) ?: []),
            fn (string $answer): bool => $answer !== '',
        ));
    }

    private function formatDigFailure(
        string $hostname,
        ?string $target,
        ProcessResultContract $result,
        bool $includeServiceDiagnostics = true,
    ): string {
        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());

        if ($target === null) {
            $message = "dnsmasq did not answer local health check {$hostname}.";
        } else {
            $message = "dnsmasq did not return {$target} for {$hostname}.";
        }

        if ($output !== '') {
            $message .= " Output: {$output}";
        }

        if ($errorOutput !== '') {
            $message .= " Error: {$errorOutput}";
        }

        if ($includeServiceDiagnostics) {
            return $this->withDnsmasqServiceDiagnostics($message);
        }

        return $message;
    }

    private function withDnsmasqServiceDiagnostics(string $message): string
    {
        $diagnostics = $this->dnsmasqServiceDiagnostics();

        if ($diagnostics === '') {
            return $message;
        }

        return "{$message} Service diagnostics: {$diagnostics}";
    }

    private function dnsmasqServiceDiagnostics(): string
    {
        try {
            $result = Process::timeout(10)->run('sudo brew services info dnsmasq');
        } catch (ProcessTimedOutException $exception) {
            return "sudo brew services info dnsmasq timed out: {$exception->getMessage()}";
        }

        $details = [];
        $output = $this->squishProcessOutput($result->output());
        $errorOutput = $this->squishProcessOutput($result->errorOutput());

        if ($output !== '') {
            $details[] = "output: {$output}";
        }

        if ($errorOutput !== '') {
            $details[] = "error: {$errorOutput}";
        }

        if ($details === [] && ! $result->successful()) {
            $details[] = 'sudo brew services info dnsmasq exited unsuccessfully';
        }

        return implode('; ', $details);
    }

    private function squishProcessOutput(string $output): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $output));
    }
}
