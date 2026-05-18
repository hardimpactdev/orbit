<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\Ca\OrbitCaService;
use App\Services\Dns\OrbitDnsServiceInstaller;
use App\Services\Gateway\GatewayApiRuntimeInstaller;
use App\Services\Vpn\WgEasyServiceInstaller;
use App\Services\WireGuard\WireGuardInterfaceInstaller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

#[Signature('orbit:internal:bootstrap-gateway-local
    {name : Gateway node name}
    {wireguard-address : WireGuard address for the gateway}
    {--identity-json= : Gateway/control WireGuard identity payload; use - to read JSON from STDIN}
    {--public-host= : Public IPv4 or DNS name that external WG peers connect to (required to provision wg-easy/orbit-dns)}
    {--tld=gateway : TLD assigned to the gateway node; used to resolve <gateway-name>.<tld> over WG-served DNS}
    {--metadata-json : Output bootstrap metadata JSON instead of only the root CA PEM}
    {--skip-runtime-install : Skip PHP-FPM, Caddy, wg-easy, and orbit-dns installation for container-only E2E topology preparation}')]
#[Description('Bootstrap gateway-local identity and root CA on the gateway host')]
class BootstrapGatewayLocalCommand extends Command
{
    public function handle(
        OrbitCaService $caService,
        WireGuardInterfaceInstaller $wireGuard,
        GatewayApiRuntimeInstaller $gatewayApiRuntimeInstaller,
        WgEasyServiceInstaller $wgEasyServiceInstaller,
        OrbitDnsServiceInstaller $orbitDnsServiceInstaller,
    ): int {
        $name = $this->stringArgument('name');
        $wireguardAddress = $this->stringArgument('wireguard-address');
        $identity = $this->identityPayload();
        $gatewayTld = $this->stringOption('tld') ?? 'gateway';
        $publicHost = $this->stringOption('public-host');

        if ($name === null || $wireguardAddress === null) {
            throw new RuntimeException('Name and wireguard-address are required.');
        }

        $enrollment = DB::transaction(function () use ($name, $wireguardAddress, $identity, $gatewayTld): ?array {
            $gateway = Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'role' => 'gateway',
                    'environment' => null,
                    'tld' => $gatewayTld,
                    'platform' => 'unknown',
                    'host' => $wireguardAddress,
                    'wireguard_address' => $wireguardAddress,
                    'gateway_endpoint' => null,
                    'user' => 'orbit',
                    'orbit_path' => '/home/orbit/orbit',
                    'status' => 'active',
                ],
            );

            if ($identity === null) {
                return null;
            }

            $control = Node::query()->updateOrCreate(
                ['name' => $identity['control']['name']],
                [
                    'role' => 'control',
                    'environment' => null,
                    'tld' => null,
                    'platform' => 'unknown',
                    'host' => $identity['control']['wireguard_address'],
                    'wireguard_address' => $identity['control']['wireguard_address'],
                    'gateway_endpoint' => $wireguardAddress,
                    'user' => 'orbit',
                    'orbit_path' => '/home/orbit/orbit',
                    'status' => 'active',
                ],
            );

            $gatewayPeer = WireGuardPeer::query()->firstOrCreate(
                ['node_id' => $gateway->id],
                [
                    'public_key' => $identity['gateway']['public_key'],
                    'private_key' => $identity['gateway']['private_key'],
                    'pre_shared_key' => $identity['gateway']['pre_shared_key'],
                    'allowed_ips' => "{$wireguardAddress}/32",
                ],
            );

            $controlPeer = WireGuardPeer::query()->firstOrCreate(
                ['node_id' => $control->id],
                [
                    'public_key' => $identity['control']['public_key'],
                    'private_key' => $identity['control']['private_key'],
                    'pre_shared_key' => $identity['control']['pre_shared_key'],
                    'allowed_ips' => "{$identity['control']['wireguard_address']}/32",
                ],
            );

            return [
                'gateway_name' => $gateway->name,
                'gateway_public_key' => $gatewayPeer->public_key,
                'gateway_private_key' => $gatewayPeer->private_key,
                'gateway_pre_shared_key' => $gatewayPeer->pre_shared_key,
                'gateway_wireguard_address' => $gateway->wireguard_address,
                'control_name' => $control->name,
                'control_public_key' => $controlPeer->public_key,
                'control_private_key' => $controlPeer->private_key,
                'control_pre_shared_key' => $controlPeer->pre_shared_key,
                'control_wireguard_address' => $control->wireguard_address,
            ];
        });

        $this->markGatewayEnvironment();

        $caService->ensureRootCa();
        $wireguardServerPublicKey = null;

        if (! (bool) $this->option('skip-runtime-install')) {
            if ($publicHost !== null) {
                $password = $this->ensureWgEasyPassword();
                $username = (string) config('services.wg_easy.username', 'orbit');
                $wgEasyServiceInstaller->install(publicHost: $publicHost, username: $username, password: $password);
                $wireguardServerPublicKey = $wgEasyServiceInstaller->publicKey();

                if ($enrollment !== null) {
                    if ($enrollment['gateway_pre_shared_key'] === null || $enrollment['control_pre_shared_key'] === null) {
                        throw new RuntimeException('WireGuard identity payload must include pre-shared keys when bootstrapping through wg-easy.');
                    }

                    $wgEasyServiceInstaller->configurePeers([
                        [
                            'name' => $enrollment['gateway_name'],
                            'private_key' => $enrollment['gateway_private_key'],
                            'public_key' => $enrollment['gateway_public_key'],
                            'pre_shared_key' => $enrollment['gateway_pre_shared_key'],
                            'address' => $enrollment['gateway_wireguard_address'],
                        ],
                        [
                            'name' => $enrollment['control_name'],
                            'private_key' => $enrollment['control_private_key'],
                            'public_key' => $enrollment['control_public_key'],
                            'pre_shared_key' => $enrollment['control_pre_shared_key'],
                            'address' => $enrollment['control_wireguard_address'],
                        ],
                    ]);
                }
            }
        }

        if ($enrollment !== null) {
            $wireGuard->install($wireguardServerPublicKey !== null && $publicHost !== null
                ? $this->gatewayClientWireGuardConfig(
                    gatewayPrivateKey: $enrollment['gateway_private_key'],
                    gatewayWireguardAddress: $wireguardAddress,
                    wireguardServerPublicKey: $wireguardServerPublicKey,
                    preSharedKey: $enrollment['gateway_pre_shared_key'],
                    endpoint: $publicHost,
                )
                : $this->gatewayWireGuardConfig(
                    gatewayPrivateKey: $enrollment['gateway_private_key'],
                    gatewayWireguardAddress: $wireguardAddress,
                    controlPublicKey: $enrollment['control_public_key'],
                    controlWireguardAddress: $enrollment['control_wireguard_address'],
                ));
        }

        if (! (bool) $this->option('skip-runtime-install')) {
            $gatewayApiRuntimeInstaller->install($wireguardAddress);

            if ($publicHost !== null) {
                $orbitDnsServiceInstaller->install();
            }
        }

        if ((bool) $this->option('metadata-json')) {
            $this->line(json_encode([
                'ca_cert' => $caService->rootCert(),
                'wireguard_server_public_key' => $wireguardServerPublicKey,
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line($caService->rootCert());

        return self::SUCCESS;
    }

    private function ensureWgEasyPassword(): string
    {
        $existing = $this->readEnvVar('WG_EASY_PASSWORD');

        if ($existing !== null) {
            return $existing;
        }

        $password = Str::random(32);

        $this->writeEnvVar('WG_EASY_PASSWORD', $password);
        config(['services.wg_easy.password' => $password]);

        return $password;
    }

    private function readEnvVar(string $key): ?string
    {
        $path = app()->environmentFilePath();

        if (! File::exists($path)) {
            return null;
        }

        $contents = File::get($path);

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) === 1) {
            $value = trim($matches[1]);

            if ($value === '') {
                return null;
            }

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                return stripcslashes(substr($value, 1, -1));
            }

            if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
                return str_replace("\\'", "'", substr($value, 1, -1));
            }

            return $value;
        }

        return null;
    }

    private function writeEnvVar(string $key, string $value): void
    {
        $path = app()->environmentFilePath();
        $contents = File::exists($path) ? File::get($path) : '';
        $line = "{$key}={$value}";

        if (preg_match('/^'.preg_quote($key, '/').'=/m', $contents) === 1) {
            $contents = (string) preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $contents);
        } else {
            $contents = rtrim($contents)."\n{$line}\n";
        }

        File::put($path, $contents);
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function markGatewayEnvironment(): void
    {
        $path = app()->environmentFilePath();
        $contents = File::exists($path) ? File::get($path) : '';
        $line = 'ORBIT_IS_GATEWAY=true';

        if (preg_match('/^ORBIT_IS_GATEWAY=/m', $contents) === 1) {
            $contents = (string) preg_replace('/^ORBIT_IS_GATEWAY=.*$/m', $line, $contents);
        } else {
            $contents = rtrim($contents)."\n{$line}\n";
        }

        File::put($path, $contents);
        config(['orbit.is_gateway' => true]);
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{
     *     gateway: array{public_key: string, private_key: string, pre_shared_key: ?string},
     *     control: array{name: string, wireguard_address: string, public_key: string, private_key: string, pre_shared_key: ?string}
     * }|null
     */
    private function identityPayload(): ?array
    {
        $value = $this->option('identity-json');

        if (! is_string($value) || $value === '') {
            return null;
        }

        $json = $value === '-' ? stream_get_contents(STDIN) : $value;

        if (! is_string($json) || trim($json) === '') {
            throw new RuntimeException('WireGuard identity payload is required when --identity-json is set.');
        }

        try {
            $payload = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('WireGuard identity payload must be valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException('WireGuard identity payload must be a JSON object.');
        }

        return [
            'gateway' => [
                'public_key' => $this->payloadString($payload, 'gateway.public_key'),
                'private_key' => $this->payloadString($payload, 'gateway.private_key'),
                'pre_shared_key' => $this->payloadOptionalString($payload, 'gateway.pre_shared_key'),
            ],
            'control' => [
                'name' => $this->payloadString($payload, 'control.name'),
                'wireguard_address' => $this->payloadString($payload, 'control.wireguard_address'),
                'public_key' => $this->payloadString($payload, 'control.public_key'),
                'private_key' => $this->payloadString($payload, 'control.private_key'),
                'pre_shared_key' => $this->payloadOptionalString($payload, 'control.pre_shared_key'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadString(array $payload, string $key): string
    {
        $value = data_get($payload, $key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("WireGuard identity payload is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadOptionalString(array $payload, string $key): ?string
    {
        $value = data_get($payload, $key);

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("WireGuard identity payload has invalid {$key}.");
        }

        return $value;
    }

    private function gatewayClientWireGuardConfig(
        string $gatewayPrivateKey,
        string $gatewayWireguardAddress,
        string $wireguardServerPublicKey,
        ?string $preSharedKey,
        string $endpoint,
    ): string {
        $lines = [
            '[Interface]',
            "PrivateKey = {$gatewayPrivateKey}",
            "Address = {$gatewayWireguardAddress}/24",
            '',
            '[Peer]',
            "PublicKey = {$wireguardServerPublicKey}",
        ];

        if ($preSharedKey !== null) {
            $lines[] = "PresharedKey = {$preSharedKey}";
        }

        return implode("\n", [
            ...$lines,
            'AllowedIPs = 10.6.0.0/24',
            "Endpoint = {$endpoint}:51820",
            'PersistentKeepalive = 25',
            '',
        ]);
    }

    private function gatewayWireGuardConfig(
        string $gatewayPrivateKey,
        string $gatewayWireguardAddress,
        string $controlPublicKey,
        string $controlWireguardAddress,
    ): string {
        return implode("\n", [
            '[Interface]',
            "PrivateKey = {$gatewayPrivateKey}",
            "Address = {$gatewayWireguardAddress}/24",
            'ListenPort = 51820',
            '',
            '[Peer]',
            "PublicKey = {$controlPublicKey}",
            "AllowedIPs = {$controlWireguardAddress}/32",
            'PersistentKeepalive = 25',
            '',
        ]);
    }
}
