<?php

namespace HardImpact\Orbit\Ui\Http\Controllers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DnsController extends Controller
{
    public function __construct(
        protected SshService $ssh,
        protected ConfigurationService $config,
        protected ServiceControlService $serviceControl,
    ) {}

    /**
     * Get DNS mappings for an environment.
     */
    public function index(Environment $environment)
    {
        $result = $this->config->getDnsMappings($environment);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to get DNS mappings',
            ]);
        }

        return response()->json([
            'success' => true,
            'mappings' => $result['mappings'] ?? [],
        ]);
    }

    /**
     * Update DNS mappings for an environment.
     */
    public function update(Request $request, Environment $environment)
    {
        $validated = $request->validate([
            'mappings' => 'required|array',
            'mappings.*.type' => ['required', 'string', Rule::in(['address', 'server'])],
            'mappings.*.tld' => 'nullable|string|regex:/^[a-z0-9-]+$/i',
            'mappings.*.value' => 'required|string',
        ]);

        // Validate IP addresses and DNS structure
        foreach ($validated['mappings'] as $index => $mapping) {
            if ($mapping['type'] === 'address') {
                // address type requires both TLD and IP
                if (empty($mapping['tld'])) {
                    return response()->json([
                        'success' => false,
                        'error' => "Mapping at index {$index}: 'address' type requires a TLD",
                    ], 422);
                }

                if (! $this->isValidIp($mapping['value'])) {
                    return response()->json([
                        'success' => false,
                        'error' => "Mapping at index {$index}: Invalid IP address '{$mapping['value']}'",
                    ], 422);
                }
            } elseif ($mapping['type'] === 'server') {
                // server type: value must be valid IP
                if (! $this->isValidIp($mapping['value'])) {
                    return response()->json([
                        'success' => false,
                        'error' => "Mapping at index {$index}: Invalid DNS server IP '{$mapping['value']}'",
                    ], 422);
                }

                // If TLD is provided for server type, validate DNS is reachable
                if (! empty($mapping['tld'])) {
                    $dnsReachable = $this->validateDnsServer($environment, $mapping['value']);
                    if (! $dnsReachable) {
                        return response()->json([
                            'success' => false,
                            'error' => "Mapping at index {$index}: DNS server '{$mapping['value']}' is not reachable",
                        ], 422);
                    }
                }
            }
        }

        // Update DNS mappings via orbit-cli
        $result = $this->config->setDnsMappings($environment, $validated['mappings']);

        if (! $result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to update DNS mappings',
            ]);
        }

        // Restart DNS service to apply changes
        $restartResult = $this->serviceControl->restart($environment, 'dns');

        return response()->json([
            'success' => true,
            'mappings' => $validated['mappings'],
            'dns_restarted' => $restartResult['success'] ?? false,
        ]);
    }

    /**
     * Validate if a string is a valid IP address.
     */
    protected function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validate if a DNS server is reachable.
     */
    protected function validateDnsServer(Environment $environment, string $ip): bool
    {
        // Try to ping the DNS server
        $command = "timeout 2 ping -c 1 -W 1 {$ip} > /dev/null 2>&1 && echo 'reachable' || echo 'unreachable'";
        $result = $this->ssh->execute($environment, $command, 5);

        if (! $result['success']) {
            return false;
        }

        return trim($result['output']) === 'reachable';
    }
}
