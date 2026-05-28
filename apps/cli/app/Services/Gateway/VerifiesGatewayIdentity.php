<?php

declare(strict_types=1);

namespace App\Services\Gateway;

interface VerifiesGatewayIdentity
{
    /**
     * @return array{gateway_name: string, gateway_ip: string, gateway_status: string, gateway_platform: string, local_node_name: string, local_node_status: string, local_node_platform: string, local_node_wg_ip: string}|array{code: string, message: string, meta: array<string, mixed>}
     */
    public function handle(string $gatewayIp, string $pemPath): array;
}
