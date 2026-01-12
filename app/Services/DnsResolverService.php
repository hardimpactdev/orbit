<?php

namespace App\Services;

use App\Models\Environment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class DnsResolverService
{
    protected string $resolverDir = '/etc/resolver';

    /**
     * Update the DNS resolver configuration for an environment's TLD.
     * Creates/updates /etc/resolver/{tld} to point to the correct DNS server.
     */
    public function updateResolver(Environment $environment, string $tld): array
    {
        // Only manage resolvers on macOS
        if (PHP_OS_FAMILY !== 'Darwin') {
            return [
                'success' => true,
                'message' => 'DNS resolver management only supported on macOS',
            ];
        }

        $resolverFile = "{$this->resolverDir}/{$tld}";
        $dnsServer = $this->getDnsServer($environment);

        try {
            // Build shell command (use double quotes inside to avoid Tcl escaping issues)
            $shellCommand = "mkdir -p {$this->resolverDir} && echo \"nameserver {$dnsServer}\" > {$resolverFile}";

            // Write expect script to temp file to avoid complex escaping
            // Using Tcl variable assignment preserves the command correctly
            $tempScript = tempnam(sys_get_temp_dir(), 'launchpad_expect_');
            $expectScript = <<<EXPECT
set cmd {{$shellCommand}}
spawn sudo sh -c \$cmd
expect {
    "Password:" { exit 1 }
    eof { exit 0 }
}
EXPECT;
            file_put_contents($tempScript, $expectScript);

            $result = Process::timeout(60)->run('expect '.escapeshellarg($tempScript));

            // Clean up temp file
            @unlink($tempScript);

            if (! $result->successful()) {
                Log::error("Failed to update DNS resolver: {$result->errorOutput()}");

                return [
                    'success' => false,
                    'error' => 'Failed to update DNS resolver. Touch ID may have been cancelled or not configured.',
                ];
            }

            // Verify the file was actually created
            if (! file_exists($resolverFile)) {
                Log::error("DNS resolver file was not created: {$resolverFile}");

                return [
                    'success' => false,
                    'error' => 'Resolver file was not created. Touch ID may have been cancelled.',
                ];
            }

            Log::info("Updated DNS resolver for .{$tld} -> {$dnsServer}");

            return [
                'success' => true,
                'message' => "DNS resolver updated: .{$tld} -> {$dnsServer}",
                'resolver_file' => $resolverFile,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to update DNS resolver: {$e->getMessage()}");

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Remove a DNS resolver file for a TLD.
     */
    public function removeResolver(string $tld): array
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return ['success' => true];
        }

        $resolverFile = "{$this->resolverDir}/{$tld}";

        if (! file_exists($resolverFile)) {
            return ['success' => true];
        }

        try {
            $shellCommand = "rm -f {$resolverFile}";

            // Write expect script to temp file to avoid complex escaping
            $tempScript = tempnam(sys_get_temp_dir(), 'launchpad_expect_');
            $expectScript = <<<EXPECT
set cmd {{$shellCommand}}
spawn sudo sh -c \$cmd
expect {
    "Password:" { exit 1 }
    eof { exit 0 }
}
EXPECT;
            file_put_contents($tempScript, $expectScript);

            $result = Process::timeout(60)->run('expect '.escapeshellarg($tempScript));

            // Clean up temp file
            @unlink($tempScript);

            if (! $result->successful()) {
                return [
                    'success' => false,
                    'error' => 'Failed to remove resolver file. Touch ID may have been cancelled.',
                ];
            }

            // Verify file was actually removed
            if (file_exists($resolverFile)) {
                return [
                    'success' => false,
                    'error' => 'Resolver file was not removed. Touch ID may have been cancelled.',
                ];
            }

            Log::info("Removed DNS resolver for .{$tld}");

            return ['success' => true];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get the DNS server address for an environment.
     * Local environments use 127.0.0.1, remote environments use their host IP.
     */
    protected function getDnsServer(Environment $environment): string
    {
        if ($environment->is_local) {
            return '127.0.0.1';
        }

        // For remote servers, resolve hostname to IP if needed
        $host = $environment->host;

        // If it's already an IP address, use it directly
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        // Try to resolve hostname to IP
        $ip = gethostbyname($host);

        // gethostbyname returns the hostname if resolution fails
        if ($ip === $host) {
            Log::warning("Could not resolve hostname {$host}, using as-is");
        }

        return $ip;
    }

    /**
     * Get all currently configured resolvers managed by Launchpad.
     */
    public function getManagedResolvers(): array
    {
        if (PHP_OS_FAMILY !== 'Darwin' || ! is_dir($this->resolverDir)) {
            return [];
        }

        $resolvers = [];
        $files = glob("{$this->resolverDir}/*");

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content && str_contains($content, 'Managed by Launchpad')) {
                $tld = basename($file);
                preg_match('/nameserver\s+(\S+)/', $content, $matches);
                $resolvers[$tld] = $matches[1] ?? 'unknown';
            }
        }

        return $resolvers;
    }

    /**
     * Sync all resolver files with current environment configurations.
     */
    public function syncAllResolvers(\App\Services\LaunchpadCli\ConfigurationService $configService): array
    {
        $results = [];
        $environments = Environment::all();

        foreach ($environments as $environment) {
            $config = $configService->getConfig($environment);
            if ($config['success'] && isset($config['data']['tld'])) {
                $tld = $config['data']['tld'];
                $results[$environment->name] = $this->updateResolver($environment, $tld);
            }
        }

        return $results;
    }
}
