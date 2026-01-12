<?php

namespace App\Services;

use App\Models\Environment;
use App\Services\LaunchpadCli\ConfigurationService;
use App\Services\LaunchpadCli\StatusService;

class DoctorService
{
    public function __construct(
        protected SshService $ssh,
        protected StatusService $status,
        protected ConfigurationService $config,
        protected DnsResolverService $dnsResolver,
    ) {}

    /**
     * Run all health checks for an environment.
     */
    public function runChecks(Environment $environment): array
    {
        $checks = [];

        // 1. SSH Connectivity
        $checks['ssh'] = $this->checkSshConnectivity($environment);

        // 2. CLI Installation
        $checks['cli'] = $this->checkCliInstallation($environment);

        // 3. Docker Services
        $checks['docker'] = $this->checkDockerServices($environment);

        // 4. Launchpad Web App (API)
        $checks['api'] = $this->checkApiConnectivity($environment);

        // 5. Environment DNS (DNS working on the environment's server)
        $checks['environment_dns'] = $this->checkEnvironmentDns($environment);

        // 6. Local DNS (can the desktop app resolve domains via environment's DNS)
        $checks['local_dns'] = $this->checkLocalDns($environment);

        // 7. Config File
        $checks['config'] = $this->checkConfigFile($environment);

        // Calculate overall status
        $allPassed = collect($checks)->every(fn ($check): bool => $check['status'] === 'ok');
        $anyFailed = collect($checks)->contains(fn ($check): bool => $check['status'] === 'error');

        return [
            'success' => true,
            'status' => $allPassed ? 'healthy' : ($anyFailed ? 'unhealthy' : 'degraded'),
            'checks' => $checks,
            'summary' => $this->generateSummary($checks),
        ];
    }

    /**
     * Check SSH connectivity to the environment.
     */
    protected function checkSshConnectivity(Environment $environment): array
    {
        if ($environment->is_local) {
            return [
                'status' => 'ok',
                'message' => 'Local environment, SSH not required',
            ];
        }

        $result = $this->ssh->execute($environment, 'echo "connected"', 10);

        if ($result['success'] && str_contains((string) ($result['output'] ?? ''), 'connected')) {
            return [
                'status' => 'ok',
                'message' => "SSH connection to {$environment->user}@{$environment->host} successful",
            ];
        }

        return [
            'status' => 'error',
            'message' => 'SSH connection failed: '.($result['error'] ?? 'Unknown error'),
            'details' => [
                'host' => $environment->host,
                'user' => $environment->user,
                'port' => $environment->port,
            ],
        ];
    }

    /**
     * Check if the launchpad CLI is installed and get its version.
     */
    protected function checkCliInstallation(Environment $environment): array
    {
        $installation = $this->status->checkInstallation($environment);

        if (! $installation['installed']) {
            return [
                'status' => 'error',
                'message' => 'Launchpad CLI not installed',
                'details' => [
                    'suggestion' => 'Install CLI from https://github.com/nckrtl/launchpad-cli/releases',
                ],
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Launchpad CLI installed',
            'details' => [
                'version' => $installation['version'] ?? 'unknown',
                'path' => $installation['path'] ?? 'unknown',
            ],
        ];
    }

    /**
     * Check Docker services status.
     */
    protected function checkDockerServices(Environment $environment): array
    {
        // Get status which includes Docker service information
        $status = $this->status->status($environment);

        if (! $status['success']) {
            // If API fails, try SSH-based check
            if (! $environment->is_local) {
                $result = $this->ssh->execute(
                    $environment,
                    "sg docker -c 'docker ps --filter name=launchpad- --format \"{{.Names}}: {{.Status}}\"'",
                    15
                );

                if ($result['success'] && ! empty($result['output'])) {
                    $containers = array_filter(explode("\n", trim((string) $result['output'])));

                    return [
                        'status' => 'ok',
                        'message' => count($containers).' Docker containers running',
                        'details' => [
                            'containers' => $containers,
                            'source' => 'ssh',
                        ],
                    ];
                }
            }

            return [
                'status' => 'error',
                'message' => 'Could not check Docker services: '.($status['error'] ?? 'Unknown error'),
            ];
        }

        $services = $status['data']['services'] ?? [];
        $running = collect($services)->filter(fn ($s): bool => ($s['status'] ?? '') === 'running')->count();
        $total = count($services);

        if ($running === $total && $total > 0) {
            return [
                'status' => 'ok',
                'message' => "All {$total} Docker services running",
                'details' => [
                    'services' => $services,
                ],
            ];
        }

        $stopped = collect($services)->filter(fn ($s): bool => ($s['status'] ?? '') !== 'running')->keys()->all();

        return [
            'status' => $running > 0 ? 'warning' : 'error',
            'message' => "{$running}/{$total} Docker services running",
            'details' => [
                'services' => $services,
                'stopped' => $stopped,
            ],
        ];
    }

    /**
     * Check API connectivity (launchpad web app).
     */
    protected function checkApiConnectivity(Environment $environment): array
    {
        // Try to get sites via API
        $result = $this->status->sites($environment);

        if ($result['success']) {
            $siteCount = count($result['data']['sites'] ?? []);

            return [
                'status' => 'ok',
                'message' => "API responding, {$siteCount} sites configured",
                'details' => [
                    'tld' => $environment->tld ?? 'unknown',
                ],
            ];
        }

        $error = $result['error'] ?? 'Unknown error';

        // Check if it's a connection error vs API error
        if (str_contains($error, 'Connection error') || str_contains($error, 'cURL error')) {
            return [
                'status' => 'error',
                'message' => 'API unreachable - launchpad web app may not be running',
                'details' => [
                    'error' => $error,
                    'suggestion' => 'Ensure launchpad services are started and the web app is accessible',
                ],
            ];
        }

        return [
            'status' => 'warning',
            'message' => 'API returned error: '.$error,
        ];
    }

    /**
     * Check DNS resolution on the environment's server.
     * Verifies that dnsmasq is working on the environment.
     */
    protected function checkEnvironmentDns(Environment $environment): array
    {
        $tld = $environment->tld ?? 'test';

        if ($environment->is_local) {
            // For local, check if resolver file exists
            $resolverPath = "/etc/resolver/{$tld}";
            if (file_exists($resolverPath)) {
                $content = file_get_contents($resolverPath);

                return [
                    'status' => 'ok',
                    'message' => "DNS resolver configured for .{$tld}",
                    'details' => [
                        'resolver_path' => $resolverPath,
                        'content' => trim($content),
                    ],
                ];
            }

            return [
                'status' => 'warning',
                'message' => "No DNS resolver found for .{$tld}",
                'details' => [
                    'suggestion' => "Create {$resolverPath} with 'nameserver 127.0.0.1'",
                ],
            ];
        }

        // For remote, try to resolve launchpad.{tld} via SSH
        $result = $this->ssh->execute(
            $environment,
            "getent hosts launchpad.{$tld} 2>/dev/null || host launchpad.{$tld} 127.0.0.1 2>/dev/null | head -1",
            10
        );

        if ($result['success'] && ! empty($result['output']) && ! str_contains((string) $result['output'], 'not found')) {
            return [
                'status' => 'ok',
                'message' => "DNS resolving for .{$tld} domain",
                'details' => [
                    'result' => trim((string) $result['output']),
                ],
            ];
        }

        // Check if dnsmasq container is running
        $dnsCheck = $this->ssh->execute(
            $environment,
            "sg docker -c 'docker ps --filter name=launchpad-dns --format \"{{.Status}}\"'",
            10
        );

        if ($dnsCheck['success'] && str_contains((string) ($dnsCheck['output'] ?? ''), 'Up')) {
            return [
                'status' => 'warning',
                'message' => 'DNS container running but resolution not verified',
                'details' => [
                    'dns_status' => trim((string) $dnsCheck['output']),
                ],
            ];
        }

        return [
            'status' => 'error',
            'message' => 'DNS not resolving for .{$tld} domains',
            'details' => [
                'suggestion' => 'Check that launchpad-dns container is running and configured correctly',
            ],
        ];
    }

    /**
     * Check DNS resolution from the machine running the desktop app.
     * Verifies that .{tld} domains can be resolved via the environment's DNS server.
     */
    protected function checkLocalDns(Environment $environment): array
    {
        $tld = $environment->tld ?? 'test';
        $testDomain = "launchpad.{$tld}";

        // First check if resolver file exists
        $resolverPath = "/etc/resolver/{$tld}";
        $resolverExists = file_exists($resolverPath);

        // DNS server location: localhost for local env, remote host IP for remote env
        $dnsServer = $environment->is_local ? '127.0.0.1' : $environment->host;

        // Try to resolve the domain using dig against the correct DNS server
        $result = \Illuminate\Support\Facades\Process::run(
            "dig +short {$testDomain} @{$dnsServer} 2>/dev/null"
        );

        $resolved = $result->successful() && ! in_array(trim($result->output()), ['', '0'], true);
        $resolvedIp = $resolved ? trim($result->output()) : null;

        // Expected resolution: 127.0.0.1 for local, remote host IP for remote
        $expectedIp = $environment->is_local ? '127.0.0.1' : $environment->host;

        if ($resolved && $resolvedIp === $expectedIp) {
            return [
                'status' => 'ok',
                'message' => "Local DNS resolves {$testDomain} correctly",
                'details' => [
                    'domain' => $testDomain,
                    'dns_server' => $dnsServer,
                    'resolved_ip' => $resolvedIp,
                    'resolver_file' => $resolverExists ? $resolverPath : 'not configured',
                ],
            ];
        }

        if ($resolved && $resolvedIp !== $expectedIp) {
            return [
                'status' => 'warning',
                'message' => "DNS resolves but to unexpected IP ({$resolvedIp} instead of {$expectedIp})",
                'details' => [
                    'domain' => $testDomain,
                    'dns_server' => $dnsServer,
                    'resolved_ip' => $resolvedIp,
                    'expected_ip' => $expectedIp,
                    'resolver_file' => $resolverExists ? $resolverPath : 'not configured',
                ],
            ];
        }

        // Not resolving - check why
        if (! $resolverExists) {
            return [
                'status' => 'error',
                'message' => "No DNS resolver configured for .{$tld} domains",
                'details' => [
                    'domain' => $testDomain,
                    'dns_server' => $dnsServer,
                    'suggestion' => "Create {$resolverPath} with 'nameserver {$dnsServer}'",
                    'can_fix' => true,
                ],
            ];
        }

        return [
            'status' => 'error',
            'message' => "DNS resolver exists but {$testDomain} is not resolving from {$dnsServer}",
            'details' => [
                'domain' => $testDomain,
                'dns_server' => $dnsServer,
                'resolver_file' => $resolverPath,
                'suggestion' => 'Check that dnsmasq is running on the DNS server',
            ],
        ];
    }

    /**
     * Check if config file exists and is valid.
     */
    protected function checkConfigFile(Environment $environment): array
    {
        $config = $this->config->getConfig($environment);

        if (! $config['success']) {
            return [
                'status' => 'error',
                'message' => 'Could not read config: '.($config['error'] ?? 'Unknown error'),
            ];
        }

        if (! ($config['exists'] ?? false)) {
            return [
                'status' => 'warning',
                'message' => 'Config file does not exist, using defaults',
                'details' => [
                    'tld' => $config['data']['tld'] ?? 'test',
                    'paths' => $config['data']['paths'] ?? [],
                ],
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Config file exists and is valid',
            'details' => [
                'tld' => $config['data']['tld'] ?? 'test',
                'paths' => $config['data']['paths'] ?? [],
                'php_versions' => $config['data']['available_php_versions'] ?? [],
            ],
        ];
    }

    /**
     * Generate a human-readable summary of the checks.
     */
    protected function generateSummary(array $checks): array
    {
        $passed = collect($checks)->filter(fn ($c): bool => $c['status'] === 'ok')->count();
        $warnings = collect($checks)->filter(fn ($c): bool => $c['status'] === 'warning')->count();
        $errors = collect($checks)->filter(fn ($c): bool => $c['status'] === 'error')->count();

        $messages = [];

        if ($errors > 0) {
            $errorChecks = collect($checks)
                ->filter(fn ($c): bool => $c['status'] === 'error')
                ->keys()
                ->map(fn ($k): string => ucfirst((string) $k))
                ->join(', ');
            $messages[] = "Failed checks: {$errorChecks}";
        }

        if ($warnings > 0) {
            $warningChecks = collect($checks)
                ->filter(fn ($c): bool => $c['status'] === 'warning')
                ->keys()
                ->map(fn ($k): string => ucfirst((string) $k))
                ->join(', ');
            $messages[] = "Warnings: {$warningChecks}";
        }

        return [
            'passed' => $passed,
            'warnings' => $warnings,
            'errors' => $errors,
            'total' => count($checks),
            'messages' => $messages,
        ];
    }

    /**
     * Run a quick connectivity check (SSH + API only).
     */
    public function quickCheck(Environment $environment): array
    {
        $checks = [];
        $checks['ssh'] = $this->checkSshConnectivity($environment);
        $checks['api'] = $this->checkApiConnectivity($environment);

        $allOk = collect($checks)->every(fn ($c): bool => $c['status'] === 'ok');

        return [
            'success' => true,
            'status' => $allOk ? 'ok' : 'error',
            'checks' => $checks,
        ];
    }

    /**
     * Attempt to fix a specific issue.
     *
     * @return array{success: bool, message: string}
     */
    public function fixIssue(Environment $environment, string $checkName): array
    {
        return match ($checkName) {
            'local_dns' => $this->fixLocalDns($environment),
            default => ['success' => false, 'message' => "No automatic fix available for '{$checkName}'"],
        };
    }

    /**
     * Fix local DNS resolver by creating/updating the resolver file.
     */
    protected function fixLocalDns(Environment $environment): array
    {
        $tld = $environment->tld ?? 'test';

        $result = $this->dnsResolver->updateResolver($environment, $tld);

        if ($result['success']) {
            return [
                'success' => true,
                'message' => "Created DNS resolver for .{$tld} domains",
            ];
        }

        return [
            'success' => false,
            'message' => $result['error'] ?? 'Failed to create DNS resolver',
        ];
    }
}
