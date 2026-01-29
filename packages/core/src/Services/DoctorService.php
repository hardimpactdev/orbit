<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Core\Services;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;

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

        // 4. Orbit Web App (API)
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
     * Check if the orbit CLI is installed and get its version.
     */
    protected function checkCliInstallation(Environment $environment): array
    {
        $installation = $this->status->checkInstallation($environment);

        if (! $installation['installed']) {
            return [
                'status' => 'error',
                'message' => 'Orbit CLI not installed',
                'details' => [
                    'suggestion' => 'Install CLI from https://github.com/nckrtl/orbit-cli/releases',
                ],
            ];
        }

        return [
            'status' => 'ok',
            'message' => 'Orbit CLI installed',
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
                    "sg docker -c 'docker ps --filter name=orbit- --format \"{{.Names}}: {{.Status}}\"'",
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
     * Check API connectivity (orbit web app).
     */
    protected function checkApiConnectivity(Environment $environment): array
    {
        // Try to get sites via API
        $result = $this->status->projects($environment);

        if ($result['success']) {
            $projectCount = count($result['data']['projects'] ?? []);

            return [
                'status' => 'ok',
                'message' => "API responding, {$projectCount} projects configured",
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
                'message' => 'API unreachable - orbit web app may not be running',
                'details' => [
                    'error' => $error,
                    'suggestion' => 'Ensure orbit services are started and the web app is accessible',
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
            // For local environments, DNS is handled by orbit-dns Docker container

            // Check if orbit-dns container is running
            $orbitDnsResult = \Illuminate\Support\Facades\Process::run(
                "docker ps --filter name=orbit-dns --format '{{.Status}}' 2>/dev/null"
            );

            if ($orbitDnsResult->successful() && str_contains($orbitDnsResult->output(), 'Up')) {
                // Verify DNS actually works
                $testResult = \Illuminate\Support\Facades\Process::run(
                    "dig +short test.{$tld} @127.0.0.1 2>/dev/null"
                );

                if ($testResult->successful() && trim($testResult->output()) === '127.0.0.1') {
                    return [
                        'status' => 'ok',
                        'message' => 'DNS resolver running (orbit-dns container)',
                        'details' => [
                            'method' => 'docker',
                            'container' => 'orbit-dns',
                            'test_domain' => "test.{$tld}",
                            'resolved_to' => '127.0.0.1',
                        ],
                    ];
                }

                // Container running but DNS not working
                return [
                    'status' => 'warning',
                    'message' => 'orbit-dns container running but DNS resolution failing',
                    'details' => [
                        'container_status' => trim($orbitDnsResult->output()),
                        'suggestion' => 'Check orbit-dns container logs: docker logs orbit-dns',
                    ],
                ];
            }

            // Container not running
            return [
                'status' => 'warning',
                'message' => 'DNS resolver not running (orbit-dns container)',
                'details' => [
                    'suggestion' => 'Start orbit services to enable DNS resolution',
                    'platform' => PHP_OS_FAMILY,
                ],
            ];
        }

        // For remote, try to resolve orbit.{tld} via SSH
        $result = $this->ssh->execute(
            $environment,
            "getent hosts orbit.{$tld} 2>/dev/null || host orbit.{$tld} 127.0.0.1 2>/dev/null | head -1",
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
            "sg docker -c 'docker ps --filter name=orbit-dns --format \"{{.Status}}\"'",
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
                'suggestion' => 'Check that orbit-dns container is running and configured correctly',
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
        $testDomain = "orbit.{$tld}";

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
                    'method' => 'orbit-dns',
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
                ],
            ];
        }

        // Not resolving - provide appropriate guidance
        if ($environment->is_local) {
            // For local env, check if orbit-dns container is running
            $containerCheck = \Illuminate\Support\Facades\Process::run(
                "docker ps --filter name=orbit-dns --format '{{.Status}}' 2>/dev/null"
            );

            $containerRunning = $containerCheck->successful() && str_contains($containerCheck->output(), 'Up');

            return [
                'status' => 'error',
                'message' => "DNS not resolving for .{$tld} domains",
                'details' => [
                    'domain' => $testDomain,
                    'dns_server' => $dnsServer,
                    'orbit_dns_running' => $containerRunning,
                    'suggestion' => $containerRunning
                        ? 'orbit-dns is running but DNS queries failing. Check container logs with: docker logs orbit-dns'
                        : 'orbit-dns container is not running. Start orbit services to enable DNS resolution',
                ],
            ];
        }

        return [
            'status' => 'error',
            'message' => "DNS not resolving for {$testDomain} from {$dnsServer}",
            'details' => [
                'domain' => $testDomain,
                'dns_server' => $dnsServer,
                'suggestion' => 'Check that orbit-dns container is running on the remote server',
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
     * Fix local DNS resolver - now guides to use orbit-dns container.
     */
    protected function fixLocalDns(Environment $environment): array
    {
        $tld = $environment->tld ?? 'test';

        // Check if orbit-dns container exists and is running
        $containerCheck = \Illuminate\Support\Facades\Process::run(
            "docker ps -a --filter name=orbit-dns --format '{{.Names}}:{{.Status}}' 2>/dev/null"
        );

        if (! $containerCheck->successful() || empty($containerCheck->output())) {
            return [
                'success' => false,
                'message' => 'orbit-dns container not found. Please ensure orbit services are properly installed.',
            ];
        }

        $containerStatus = trim($containerCheck->output());

        if (str_contains($containerStatus, 'Up')) {
            // Container is running, DNS should work
            return [
                'success' => false,
                'message' => 'orbit-dns container is already running. If DNS is still not working, check container logs with: docker logs orbit-dns',
            ];
        }

        // Try to start the container
        $startResult = \Illuminate\Support\Facades\Process::run(
            'docker start orbit-dns 2>&1'
        );

        if ($startResult->successful()) {
            return [
                'success' => true,
                'message' => "Started orbit-dns container for .{$tld} domain resolution",
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to start orbit-dns container: '.$startResult->output(),
        ];
    }
}
