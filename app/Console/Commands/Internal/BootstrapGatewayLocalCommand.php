<?php

declare(strict_types=1);

namespace App\Console\Commands\Internal;

use App\Models\Node;
use App\Models\WireGuardPeer;
use App\Services\Ca\OrbitCaService;
use App\Services\WireGuard\WireGuardInterfaceInstaller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

#[Signature('orbit:internal:bootstrap-gateway-local
    {name : Gateway node name}
    {wireguard-address : WireGuard address for the gateway}
    {--identity-json= : Gateway/control WireGuard identity payload; use - to read JSON from STDIN}')]
#[Description('Bootstrap gateway-local identity and root CA on the gateway host')]
class BootstrapGatewayLocalCommand extends Command
{
    public function handle(OrbitCaService $caService, WireGuardInterfaceInstaller $wireGuard): int
    {
        $name = $this->stringArgument('name');
        $wireguardAddress = $this->stringArgument('wireguard-address');
        $identity = $this->identityPayload();

        if ($name === null || $wireguardAddress === null) {
            throw new RuntimeException('Name and wireguard-address are required.');
        }

        $enrollment = DB::transaction(function () use ($name, $wireguardAddress, $identity): ?array {
            Node::query()->where('is_local', true)->update(['is_local' => false]);

            $gateway = Node::query()->updateOrCreate(
                ['name' => $name],
                [
                    'role' => 'gateway',
                    'environment' => null,
                    'tld' => null,
                    'platform' => 'unknown',
                    'host' => $wireguardAddress,
                    'wireguard_address' => $wireguardAddress,
                    'gateway_endpoint' => null,
                    'ssh_user' => 'orbit',
                    'user' => 'orbit',
                    'orbit_path' => '/home/orbit/orbit',
                    'status' => 'active',
                    'is_local' => true,
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
                    'ssh_user' => 'orbit',
                    'user' => 'orbit',
                    'orbit_path' => '/home/orbit/orbit',
                    'status' => 'active',
                    'is_local' => false,
                ],
            );

            $gatewayPeer = WireGuardPeer::query()->firstOrCreate(
                ['node_id' => $gateway->id],
                [
                    'public_key' => $identity['gateway']['public_key'],
                    'private_key' => $identity['gateway']['private_key'],
                    'allowed_ips' => "{$wireguardAddress}/32",
                ],
            );

            $controlPeer = WireGuardPeer::query()->firstOrCreate(
                ['node_id' => $control->id],
                [
                    'public_key' => $identity['control']['public_key'],
                    'private_key' => $identity['control']['private_key'],
                    'allowed_ips' => "{$identity['control']['wireguard_address']}/32",
                ],
            );

            return [
                'gateway_private_key' => $gatewayPeer->private_key,
                'control_public_key' => $controlPeer->public_key,
                'control_wireguard_address' => $control->wireguard_address,
            ];
        });

        if ($enrollment !== null) {
            $wireGuard->install($this->gatewayWireGuardConfig(
                gatewayPrivateKey: $enrollment['gateway_private_key'],
                gatewayWireguardAddress: $wireguardAddress,
                controlPublicKey: $enrollment['control_public_key'],
                controlWireguardAddress: $enrollment['control_wireguard_address'],
            ));
        }

        $caService->ensureRootCa();

        $this->line($caService->rootCert());

        return self::SUCCESS;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{
     *     gateway: array{public_key: string, private_key: string},
     *     control: array{name: string, wireguard_address: string, public_key: string, private_key: string}
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
            ],
            'control' => [
                'name' => $this->payloadString($payload, 'control.name'),
                'wireguard_address' => $this->payloadString($payload, 'control.wireguard_address'),
                'public_key' => $this->payloadString($payload, 'control.public_key'),
                'private_key' => $this->payloadString($payload, 'control.private_key'),
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
