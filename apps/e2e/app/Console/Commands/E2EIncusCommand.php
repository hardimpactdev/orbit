<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('e2e:incus
    {--start : Acquire a retained Incus topology}
    {--stop : Release a retained Incus topology}
    {--live : Acquire a retained Incus topology and mint a local operator WireGuard identity}
    {--topology=operator_gateway_app-dev : Prepared topology kind to acquire}
    {--id= : Retained topology id to release}
    {--all : Release every recorded retained topology}
    {--checkout-roles= : Comma-separated roles to overlay the current checkout onto}
    {--operator-name= : Operator identity name to mint for --live (defaults to mac-<id>)}
    {--gateway-name= : Local gateway entry name for --live follow-up commands (defaults to incus-<id>)}
    {--wireguard-endpoint= : Public or LAN WireGuard endpoint to write into the --live config}
    {--dry-run : Render the acquisition plan without provisioning a topology}
    {--json : Output as JSON}')]
#[Description('Start, expose, or stop retained Incus topologies for manual diagnosis')]
class E2EIncusCommand extends Command
{
    private const string WireGuardEndpointEnv = 'ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT';

    private const string WireGuardEndpointAliasEnv = 'ORBIT_E2E_LIVE_WG_ENDPOINT';

    /** @var (Closure(string): IncusHost)|null */
    private ?Closure $hostFactory = null;

    /**
     * Override the Incus host factory for command tests.
     *
     * @param  Closure(string): IncusHost  $factory
     */
    public function hostFactoryUsing(Closure $factory): void
    {
        $this->hostFactory = $factory;
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $start = (bool) $this->option('start');
        $stop = (bool) $this->option('stop');
        $live = (bool) $this->option('live');

        if (count(array_filter([$start, $stop, $live])) !== 1) {
            return $this->renderError('validation_failed', 'Choose exactly one Incus topology action: --start, --stop, or --live.', $json);
        }

        if ($start) {
            return $this->start();
        }

        if ($live) {
            return $this->live();
        }

        return $this->stop();
    }

    private function start(): int
    {
        $parameters = [
            '--provider' => 'incus',
            '--kind' => (string) $this->option('topology'),
            '--json' => (bool) $this->option('json'),
            '--dry-run' => (bool) $this->option('dry-run'),
        ];

        $checkoutRoles = $this->option('checkout-roles');

        if (is_string($checkoutRoles) && trim($checkoutRoles) !== '') {
            $parameters['--checkout-roles'] = $checkoutRoles;
        }

        return $this->call('e2e:dev-topology', $parameters);
    }

    private function stop(): int
    {
        $parameters = [
            '--json' => (bool) $this->option('json'),
            '--all' => (bool) $this->option('all'),
        ];

        $id = $this->option('id');

        if (is_string($id) && trim($id) !== '') {
            $parameters['id'] = $id;
        }

        return $this->call('e2e:dev-topology:release', $parameters);
    }

    private function live(): int
    {
        $json = (bool) $this->option('json');

        if ((bool) $this->option('dry-run')) {
            return $this->renderError('validation_failed', '--live cannot be combined with --dry-run because no topology or operator identity would be created.', $json);
        }

        $endpoint = $this->wireGuardEndpoint();

        if ($endpoint === null) {
            return $this->renderError(
                'validation_failed',
                'Set ORBIT_E2E_LIVE_WIREGUARD_ENDPOINT in .env.e2e, or pass --wireguard-endpoint=<host:port>, before using --live.',
                $json,
            );
        }

        $kindInput = (string) $this->option('topology');
        $kind = E2ETopologyKind::tryFromInput($kindInput);

        if ($kind === null) {
            return $this->renderError('validation_failed', "Unsupported E2E topology kind [{$kindInput}].", $json);
        }

        try {
            $devTopology = app(E2EDevTopologyCommand::class);
            $displayRoles = $devTopology->displayCheckoutRolesForInput($kind, $this->stringOption('checkout-roles'));
            $manifest = $devTopology->acquireRetainedIncusTopology($kind, $displayRoles);
            $operatorName = $this->nodeName($this->stringOption('operator-name') ?? "mac-{$manifest['id']}");
            $gatewayName = $this->nodeName($this->stringOption('gateway-name') ?? "incus-{$manifest['id']}");
            $enrollment = $this->mintOperatorIdentity($manifest, $operatorName);
            $wireGuardConfig = $this->rewriteWireGuardEndpoint($this->wireGuardConfig($enrollment), $endpoint);
            $wireGuardConfigPath = $this->writeWireGuardConfig($manifest['id'], $operatorName, $wireGuardConfig);
            $payload = $this->livePayload($manifest, $operatorName, $gatewayName, $endpoint, $wireGuardConfig, $wireGuardConfigPath, $enrollment);
        } catch (Throwable $exception) {
            return $this->renderError('live_setup_failed', $exception->getMessage(), $json);
        }

        return $this->renderLive($payload, $json);
    }

    /**
     * @param  array{
     *     id: string,
     *     kind: string,
     *     provider: string,
     *     host: string,
     *     run_id: string,
     *     ssh_key_path: string,
     *     gateway_ip: string,
     *     instances: array<string, string>,
     *     checkouts: array<string, string>,
     *     created_at: string
     * }  $manifest
     * @return array<string, mixed>
     */
    private function mintOperatorIdentity(array $manifest, string $operatorName): array
    {
        $instance = $manifest['instances']['operator'] ?? null;

        if (! is_string($instance) || $instance === '') {
            throw new RuntimeException('Live Incus setup requires an operator instance in the retained topology manifest.');
        }

        $hostName = $manifest['host'];
        $config = E2EConfig::fromEnvironment()->forHost($hostName);
        $checkout = $manifest['checkouts']['operator'] ?? "/home/{$config->operatorUser}/orbit-current";
        $inner = 'cd '.escapeshellarg($checkout).' && orbit node:new '.$operatorName.' --operator --json';
        $result = $this->hostFor($hostName)->run(sprintf(
            'incus exec %s -- sudo -u %s bash -lc %s',
            escapeshellarg($instance),
            escapeshellarg($config->operatorUser),
            escapeshellarg($inner),
        ), timeoutSeconds: $config->timeoutSeconds);

        if (! $result->successful()) {
            throw new RuntimeException(trim($result->errorOutput()) ?: "Could not mint operator identity [{$operatorName}] inside [{$instance}].");
        }

        return $this->parseOperatorEnrollment($result->output());
    }

    private function hostFor(string $host): IncusHost
    {
        if ($this->hostFactory !== null) {
            return ($this->hostFactory)($host);
        }

        return new IncusHost(E2EConfig::fromEnvironment()->forHost($host));
    }

    /**
     * @return array<string, mixed>
     */
    private function parseOperatorEnrollment(string $output): array
    {
        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($decoded)) {
                continue;
            }

            $enrollment = $this->extractOperatorEnrollment($decoded);

            if ($enrollment !== null) {
                return $enrollment;
            }

            $error = $this->extractOperatorEnrollmentError($decoded);

            if ($error !== null) {
                throw new RuntimeException($error);
            }
        }

        throw new RuntimeException('Could not parse operator identity output from orbit node:new --operator --json.');
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>|null
     */
    private function extractOperatorEnrollment(array $decoded): ?array
    {
        $direct = $decoded['success']['data'] ?? null;

        if (is_array($direct) && isset($direct['wireguard'])) {
            return $direct;
        }

        if (($decoded['event'] ?? null) !== 'complete') {
            return null;
        }

        $data = $decoded['data'] ?? null;
        $result = is_array($data) ? ($data['data']['result'] ?? $data['result'] ?? null) : null;

        if (is_array($result)) {
            $resultData = $result['success']['data'] ?? null;

            if (is_array($resultData) && isset($resultData['wireguard'])) {
                return $resultData;
            }

            if (isset($result['wireguard'])) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function extractOperatorEnrollmentError(array $decoded): ?string
    {
        $directError = $decoded['error']['message'] ?? null;

        if (is_string($directError) && trim($directError) !== '') {
            return $directError;
        }

        if (($decoded['event'] ?? null) !== 'error') {
            return null;
        }

        $data = $decoded['data'] ?? [];

        if (! is_array($data)) {
            return 'Operator identity minting failed.';
        }

        $message = $data['message'] ?? $data['data']['message'] ?? null;

        return is_string($message) && trim($message) !== ''
            ? $message
            : 'Operator identity minting failed.';
    }

    /**
     * @param  array<string, mixed>  $enrollment
     */
    private function wireGuardConfig(array $enrollment): string
    {
        $config = $enrollment['wireguard']['config'] ?? null;

        if (! is_string($config) || trim($config) === '') {
            throw new RuntimeException('Operator identity output did not include a WireGuard configuration.');
        }

        return trim($config)."\n";
    }

    private function rewriteWireGuardEndpoint(string $config, string $endpoint): string
    {
        $rewritten = preg_replace('/^Endpoint\s*=.*$/m', "Endpoint = {$endpoint}", $config, 1, $count);

        if ($rewritten === null || $count !== 1) {
            throw new RuntimeException('Operator WireGuard configuration did not include an Endpoint line to rewrite.');
        }

        return $rewritten;
    }

    private function writeWireGuardConfig(string $topologyId, string $operatorName, string $config): string
    {
        $store = E2EDevTopologyManifestStore::fromEnvironment(repo_path());
        $directory = $store->directory();

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/'.$this->fileName("{$topologyId}-{$operatorName}.conf");
        $written = file_put_contents($path, $config);

        if ($written === false) {
            throw new RuntimeException("Could not write WireGuard config to [{$path}].");
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $enrollment
     * @return array<string, mixed>
     */
    private function livePayload(
        array $manifest,
        string $operatorName,
        string $gatewayName,
        string $endpoint,
        string $wireGuardConfig,
        string $wireGuardConfigPath,
        array $enrollment,
    ): array {
        $gatewayAddCommand = "orbit gateway:add {$manifest['gateway_ip']} --name={$gatewayName}";
        $gatewayUseCommand = "orbit gateway:use {$gatewayName}";
        $releaseCommand = "composer e2e:incus -- --stop --id={$manifest['id']}";

        return [
            ...$manifest,
            'operator_node' => $operatorName,
            'operator_wireguard_ip' => $this->operatorWireGuardIp($enrollment),
            'wireguard_endpoint' => $endpoint,
            'wireguard_config_path' => $wireGuardConfigPath,
            'wireguard_config' => $wireGuardConfig,
            'gateway_name' => $gatewayName,
            'gateway_add_command' => $gatewayAddCommand,
            'gateway_use_command' => $gatewayUseCommand,
            'release_command' => $releaseCommand,
            'next_steps' => [
                'Import and activate the WireGuard configuration on this Mac.',
                "Run `{$gatewayAddCommand}`.",
                "Run `{$gatewayUseCommand}` if another gateway is active.",
                'Verify access with `orbit gateway:list` or `orbit node:list --json`.',
                "Release the topology with `{$releaseCommand}` when you are done.",
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $enrollment
     */
    private function operatorWireGuardIp(array $enrollment): ?string
    {
        $wireGuardIp = $enrollment['node']['addresses']['wireguard'] ?? null;

        return is_string($wireGuardIp) && $wireGuardIp !== '' ? $wireGuardIp : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderLive(array $payload, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'success' => [
                    'live_topology' => $payload,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Live Incus topology [{$payload['id']}] is ready.");
        $this->line("Kind: {$payload['kind']}");
        $this->line("Provider: {$payload['provider']} (host {$payload['host']})");
        $this->line("Gateway API: http://{$payload['gateway_ip']}");
        $this->line("Operator identity: {$payload['operator_node']}");

        if (is_string($payload['operator_wireguard_ip'] ?? null)) {
            $this->line("Operator WireGuard IP: {$payload['operator_wireguard_ip']}");
        }

        $this->line("WireGuard endpoint: {$payload['wireguard_endpoint']}");
        $this->line("WireGuard config path: {$payload['wireguard_config_path']}");
        $this->line('');
        $this->line('WireGuard config');
        $this->line('---');
        $this->line(rtrim((string) $payload['wireguard_config']));
        $this->line('---');
        $this->line('');
        $this->line('Next steps:');

        foreach ($payload['next_steps'] as $index => $step) {
            $number = $index + 1;
            $this->line("{$number}. {$step}");
        }

        $this->line('');
        $this->line("Release: {$payload['release_command']}");

        return self::SUCCESS;
    }

    private function wireGuardEndpoint(): ?string
    {
        $endpoint = $this->stringOption('wireguard-endpoint')
            ?? $this->envString(self::WireGuardEndpointEnv)
            ?? $this->envString(self::WireGuardEndpointAliasEnv);

        if ($endpoint === null) {
            return null;
        }

        return trim($endpoint);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function envString(string $key): ?string
    {
        $value = env($key);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $value = getenv($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function nodeName(string $value): string
    {
        $name = strtolower($value);
        $name = preg_replace('/[^a-z0-9_.-]+/', '-', $name) ?? $name;
        $name = trim($name, '-_.');

        if ($name === '') {
            throw new RuntimeException('Live Incus operator and gateway names must contain at least one letter or number.');
        }

        return $name;
    }

    private function fileName(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? $value;
    }

    private function renderError(string $code, string $message, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->components->error($message);

        return self::FAILURE;
    }
}
