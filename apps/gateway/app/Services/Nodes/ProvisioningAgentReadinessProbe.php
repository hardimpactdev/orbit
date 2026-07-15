<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class ProvisioningAgentReadinessProbe
{
    public function waitUntilReady(Node $node): void
    {
        $address = trim((string) $node->wireguard_address);

        if ($address === '') {
            throw new RuntimeException('Provisioning node does not have a WireGuard address.');
        }

        $attempts = max(1, (int) config('orbit.node_bootstrap.readiness_attempts', 30));
        $delayMilliseconds = max(0, (int) config('orbit.node_bootstrap.readiness_delay_milliseconds', 1000));
        $lastStatus = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::connectTimeout(2)
                    ->timeout(2)
                    ->get("http://{$address}:9477/v1/commands");
                $lastStatus = $response->status();

                if ($lastStatus === 405) {
                    return;
                }
            } catch (ConnectionException) {
                $lastStatus = null;
            }

            if ($attempt < $attempts && $delayMilliseconds > 0) {
                usleep($delayMilliseconds * 1000);
            }
        }

        $detail = $lastStatus === null
            ? 'the Agent listener could not be reached'
            : "the Agent readiness endpoint returned HTTP {$lastStatus}";

        throw new RuntimeException(
            "Orbit Agent did not become ready at {$address}:9477; {$detail}.",
        );
    }
}
