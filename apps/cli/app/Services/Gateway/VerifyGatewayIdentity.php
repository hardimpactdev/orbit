<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Facades\Http;

final class VerifyGatewayIdentity implements VerifiesGatewayIdentity
{
    private const int TIMEOUT = 15;

    /**
     * Verify the local WireGuard identity against the gateway /api/me endpoint.
     *
     * Returns success data on success, or an array with 'code', 'message', 'meta' on failure.
     *
     * @param  string  $gatewayIp  The WireGuard IP of the gateway
     * @param  string  $pemPath  Path to the locally installed gateway CA PEM
     * @return array{gateway_name: string, gateway_ip: string, gateway_status: string, gateway_platform: string, local_node_name: string, local_node_status: string, local_node_platform: string, local_node_wg_ip: string}|array{code: string, message: string, meta: array<string, mixed>}
     */
    public function handle(string $gatewayIp, string $pemPath): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->withOptions(['verify' => $pemPath])
                ->acceptJson()
                ->get("https://{$gatewayIp}/api/me");
        } catch (\Throwable) {
            return [
                'code' => 'gateway_unavailable',
                'message' => "Gateway at {$gatewayIp} is unreachable.",
                'meta' => ['gateway_ip' => $gatewayIp],
            ];
        }

        $status = $response->status();

        if ($status === 403) {
            return [
                'code' => 'node.identity_unknown',
                'message' => "This peer is not registered on the gateway at {$gatewayIp}. Ask your admin to register this machine on the gateway, then retry.",
                'meta' => ['gateway_ip' => $gatewayIp],
            ];
        }

        if (! $response->successful()) {
            return [
                'code' => 'gateway_unavailable',
                'message' => "Gateway at {$gatewayIp} returned HTTP {$status} for /api/me.",
                'meta' => ['gateway_ip' => $gatewayIp, 'status' => $status],
            ];
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            return [
                'code' => 'node.gateway_api_error',
                'message' => "Gateway at {$gatewayIp} returned an invalid identity response.",
                'meta' => ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/me'],
            ];
        }

        $data = $decoded['success']['data'] ?? $decoded['data'] ?? $decoded;

        if (! is_array($data)) {
            return [
                'code' => 'node.gateway_api_error',
                'message' => "Gateway at {$gatewayIp} returned an invalid identity response.",
                'meta' => ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/me'],
            ];
        }

        $self = $data['self'] ?? null;
        $gateway = $data['gateway'] ?? null;

        if (! is_array($self)) {
            return [
                'code' => 'node.gateway_api_error',
                'message' => "Gateway at {$gatewayIp} returned an invalid identity response.",
                'meta' => ['gateway_ip' => $gatewayIp, 'endpoint' => '/api/me'],
            ];
        }

        if (! is_array($gateway)) {
            $gateway = [
                'name' => 'gateway',
                'wg_ip' => $gatewayIp,
                'status' => 'active',
            ];
        }

        return [
            'gateway_name' => (string) ($gateway['name'] ?? 'gateway'),
            'gateway_ip' => $gatewayIp,
            'gateway_status' => (string) ($gateway['status'] ?? 'active'),
            'gateway_platform' => (string) ($gateway['platform'] ?? 'unknown'),
            'local_node_name' => (string) ($self['name'] ?? 'control'),
            'local_node_status' => (string) ($self['status'] ?? 'active'),
            'local_node_platform' => (string) ($self['platform'] ?? 'unknown'),
            'local_node_wg_ip' => (string) ($self['wg_ip'] ?? $self['addresses']['wireguard'] ?? ''),
        ];
    }
}
