<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('writes lists and removes custom proxy intent on a prepared app node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $domain = 'e2e-proxy-'.strtolower(bin2hex(random_bytes(3))).'.test';

    try {
        proxyCommandPrepareAppNode($topology, $checkout);

        $add = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit proxy:add "
            .escapeshellarg($domain)
            .' --node=app-dev-1 --upstream=http://127.0.0.1:5173 --json',
            timeoutSeconds: 120,
        );
        $addPayload = proxyCommandPayload($add->output());

        $addStatus = $addPayload['success']['data']['route']['status'] ?? null;
        $addWarningCodes = collect($addPayload['success']['meta']['warnings'] ?? [])->pluck('code')->all();

        expect($add->successful())
            ->toBeTrue()
            ->and($addPayload['success']['data']['route'])
            ->toMatchArray([
                'domain' => $domain,
                'kind' => 'proxy',
                'node' => 'app-dev-1',
            ])
            ->and($addPayload['success']['data']['route']['owner']['type'])
            ->toBe('custom')
            ->and($addPayload['success']['data']['route']['target'])
            ->toBe([
                'type' => 'upstream',
                'value' => 'http://127.0.0.1:5173',
            ])
            // One-step custom add: healthy path converges; apply failure keeps the
            // row and returns success with proxy.enactment_failed (never deferred).
            ->and(in_array($addStatus, ['converged', 'failed', 'partial'], true))
            ->toBeTrue()
            ->and($addWarningCodes)
            ->not->toContain('proxy.enactment_deferred');

        if ($addStatus === 'converged') {
            expect($addWarningCodes)->not->toContain('proxy.enactment_failed');
        } else {
            expect($addWarningCodes)->toContain('proxy.enactment_failed');
        }

        $list = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit proxy:list --node=app-dev-1 --filter=custom --json",
            timeoutSeconds: 120,
        );
        $routes = proxyCommandPayload($list->output())['success']['data']['routes'];

        expect(array_column($routes, 'domain'))->toContain($domain);

        $missingConsent = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit proxy:remove ".escapeshellarg($domain).' --json || true',
            timeoutSeconds: 120,
        );
        $missingConsentPayload = proxyCommandPayload($missingConsent->output());

        expect($missingConsentPayload['error']['code'])->toBe('destructive_consent_required');

        $remove = $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit proxy:remove ".escapeshellarg($domain).' --force --json',
            timeoutSeconds: 120,
        );
        $removePayload = proxyCommandPayload($remove->output());

        if ($remove->successful()) {
            expect($removePayload['success']['data']['route'])
                ->toMatchArray([
                    'domain' => $domain,
                    'kind' => 'proxy',
                    'node' => 'app-dev-1',
                    'status' => 'removed',
                ])
                ->and($removePayload['success']['meta']['backend_removed'] ?? null)
                ->toBeTrue()
                ->and($removePayload['success']['meta']['tls_removed'] ?? null)
                ->toBeTrue()
                ->and(collect($removePayload['success']['meta']['warnings'] ?? [])->pluck('code')->all())
                ->not->toContain('proxy.cleanup_deferred')
                ->not->toContain('proxy.cleanup_failed');

            $after = $topology->ssh(
                'gateway',
                "cd {$checkout} && orbit proxy:list --node=app-dev-1 --filter=custom --json",
                timeoutSeconds: 120,
            );
            $afterRoutes = proxyCommandPayload($after->output())['success']['data']['routes'];

            expect(array_column($afterRoutes, 'domain'))->not->toContain($domain);
        } else {
            // Safe cleanup contract: registry row remains; hard proxy.cleanup_failed.
            expect($removePayload['error']['code'] ?? null)
                ->toBe('proxy.cleanup_failed')
                ->and($removePayload['error']['meta']['backend_removed'] ?? null)
                ->toBeFalse()
                ->and($removePayload['error']['meta']['tls_removed'] ?? null)
                ->toBeFalse()
                ->and($removePayload['error']['meta']['next_command'] ?? null)
                ->toContain('doctor --family=proxy --restore');

            $after = $topology->ssh(
                'gateway',
                "cd {$checkout} && orbit proxy:list --node=app-dev-1 --filter=custom --json",
                timeoutSeconds: 120,
            );
            $afterRoutes = proxyCommandPayload($after->output())['success']['data']['routes'];

            expect(array_column($afterRoutes, 'domain'))->toContain($domain);
        }
    } finally {
        $topology->ssh(
            'gateway',
            "cd {$checkout} && orbit proxy:remove ".escapeshellarg($domain).' --force --json >/dev/null 2>&1 || true',
            timeoutSeconds: 120,
        );
        $topology->cleanup();
    }
})->group(
    'e2e-feature',
    'e2e-feature-canary',
    'e2e-feature-operator_gateway_app-dev',
    'e2e-feature-operator-gateway-dev',
);

function proxyCommandPrepareAppNode(E2ETopologyHarness $topology, string $checkout): void
{
    $topology->ssh(
        'gateway',
        "cd {$checkout} && php apps/gateway/artisan tinker --execute="
            .escapeshellarg(
                '$node = \App\Models\Node::query()->where("name", "app-dev-1")->firstOrFail(); $node->update(["status" => "active"]); echo "prepared";',
            ),
        timeoutSeconds: 120,
    );
}

/**
 * @return array<string, mixed>
 */
function proxyCommandPayload(string $output): array
{
    return json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
}
