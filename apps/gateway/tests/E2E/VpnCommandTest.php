<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

function vpnCommandPrepareFakeBackend(E2ETopologyHarness $topology, string $backendPath): void
{
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $backendPathValue = var_export($backendPath, true);
    $publicKey = trim($topology->ssh(
        'operator',
        'install -d -m 700 ~/.ssh && if ! test -f ~/.ssh/id_ed25519; then ssh-keygen -t ed25519 -N "" -f ~/.ssh/id_ed25519 -C orbit-e2e-operator >/dev/null; fi && cat ~/.ssh/id_ed25519.pub',
        timeoutSeconds: 60,
    )->output());

    $topology->ssh(
        'gateway',
        sprintf(
            'install -d -m 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && grep -qxF %1$s ~/.ssh/authorized_keys || printf "%%s\n" %1$s >> ~/.ssh/authorized_keys',
            escapeshellarg($publicKey),
        ),
        timeoutSeconds: 60,
    );

    $script = <<<PHP
\$gateway = \\App\\Models\\Node::query()->where('name', 'gateway-1')->first();

if (\$gateway instanceof \\App\\Models\\Node) {
    \$gateway->update(['orbit_path' => '{$topology->checkout('gateway')}', 'status' => 'active']);
}

\\Spatie\\Activitylog\\Models\\Activity::query()->delete();

file_put_contents({$backendPathValue}, json_encode([
    'clients' => [
        [
            'id' => 'client-1',
            'name' => 'laptop',
            'address' => '10.6.0.70',
            'enabled' => true,
            'latest_handshake_at' => '2026-05-07T10:00:00Z',
        ],
    ],
], JSON_THROW_ON_ERROR));

echo 'prepared';
PHP;

    $topology->ssh(
        'gateway',
        "cd {$checkout} && orbit tinker --execute=".escapeshellarg($script),
        timeoutSeconds: 120,
    );

    $gatewayCheckoutValue = var_export($topology->checkout('gateway'), true);
    $operatorScript = <<<PHP
\$gateway = \App\Models\Node::query()
    ->where('role', 'gateway')
    ->orWhere('name', 'gateway-1')
    ->first();

if (! \$gateway instanceof \App\Models\Node) {
    \$gateway = new \App\Models\Node(['name' => 'gateway-1']);
}

\$hostKey = app(\App\Services\Security\SshHostKeyPinner::class)->pin('gateway');

\$gateway->forceFill([
    'role' => 'gateway',
    'host' => 'gateway',
    'wireguard_address' => '10.6.0.2',
    'user' => 'orbit',
    'orbit_path' => {$gatewayCheckoutValue},
    'status' => 'active',
    'host_key_type' => \$hostKey->type,
    'host_key_fingerprint' => \$hostKey->fingerprint,
    'host_key_public' => \$hostKey->publicKey,
    'host_key_pin_mode' => \$hostKey->pinMode,
    'host_key_pinned_at' => now(),
])->save();

\App\Models\NodeRoleAssignment::query()->updateOrCreate(
    ['node_id' => \$gateway->id, 'role' => 'gateway'],
    ['status' => 'active', 'settings' => [], 'last_error' => null, 'converged_at' => now()],
);

\App\Models\NodeRoleAssignment::query()->updateOrCreate(
    ['node_id' => \$gateway->id, 'role' => 'vpn'],
    [
        'status' => 'active',
        'settings' => [
            'public_endpoint' => 'gateway',
            'wireguard_cidr' => '10.6.0.0/24',
            'wireguard_port' => 51820,
            'dns_ip' => '10.6.0.1',
        ],
        'last_error' => null,
        'converged_at' => now(),
    ],
);

echo 'prepared';
PHP;

    $topology->ssh(
        'operator',
        'cd '.escapeshellarg($topology->checkout('operator')).' && orbit tinker --execute='.escapeshellarg($operatorScript),
        timeoutSeconds: 120,
    );

    $topology->ssh(
        'gateway',
        "cd {$checkout} && (grep -v '^ORBIT_VPN_FAKE_BACKEND_PATH=' apps/gateway/.env 2>/dev/null || true) > apps/gateway/.env.vpn-e2e && printf '%s\n' ORBIT_VPN_FAKE_BACKEND_PATH=".escapeshellarg($backendPath).' >> apps/gateway/.env.vpn-e2e && mv apps/gateway/.env.vpn-e2e apps/gateway/.env',
        timeoutSeconds: 60,
    );
}

/**
 * @return array<string, mixed>
 */
function vpnCommandGatewayState(E2ETopologyHarness $topology, string $backendPath): array
{
    $backendPathValue = var_export($backendPath, true);
    $script = <<<PHP
echo json_encode([
    'backend' => json_decode((string) file_get_contents({$backendPathValue}), true, 512, JSON_THROW_ON_ERROR),
    'activity' => \\Spatie\\Activitylog\\Models\\Activity::query()
        ->selectRaw('event, JSON_EXTRACT(properties, "$.type") as effect, count(*) as total')
        ->groupBy('event', 'effect')
        ->orderBy('event')
        ->get()
        ->map(fn (\$activity) => [
            'event' => \$activity->event,
            'effect' => trim((string) \$activity->effect, '"'),
            'total' => (int) \$activity->total,
        ])
        ->all(),
], JSON_THROW_ON_ERROR);
PHP;

    $result = $topology->ssh(
        'gateway',
        'cd '.escapeshellarg($topology->checkout('gateway')).' && orbit tinker --execute='.escapeshellarg($script),
        timeoutSeconds: 120,
    );

    return json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);
}

it('administers VPN clients through gateway execution and operator SSH forwarding', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway)
        ->withCurrentCheckout(roles: ['operator', 'gateway']);
    $backendPath = '/tmp/orbit-vpn-'.strtolower(bin2hex(random_bytes(3))).'.json';
    $gatewayCheckout = escapeshellarg($topology->checkout('gateway'));
    $operatorCheckout = escapeshellarg($topology->checkout('operator'));

    try {
        vpnCommandPrepareFakeBackend($topology, $backendPath);

        $gatewayList = $topology->ssh(
            'gateway',
            "cd {$gatewayCheckout} && orbit vpn-client:list --json",
            timeoutSeconds: 120,
        );
        $gatewayListPayload = json_decode(trim($gatewayList->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($gatewayList->successful())->toBeTrue()
            ->and(array_column($gatewayListPayload['success']['data']['clients'], 'name'))->toContain('laptop');

        $created = $topology->ssh(
            'operator',
            "cd {$operatorCheckout} && orbit vpn-client:new tablet --config --json",
            timeoutSeconds: 120,
        );
        $createdPayload = json_decode(trim($created->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $disabled = $topology->ssh(
            'operator',
            "cd {$operatorCheckout} && orbit vpn-client:disable tablet --json",
            timeoutSeconds: 120,
        );
        $disabledPayload = json_decode(trim($disabled->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $enabled = $topology->ssh(
            'operator',
            "cd {$operatorCheckout} && orbit vpn-client:enable tablet --json",
            timeoutSeconds: 120,
        );
        $enabledPayload = json_decode(trim($enabled->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $removed = $topology->ssh(
            'operator',
            "cd {$operatorCheckout} && orbit vpn-client:remove tablet --force --json",
            timeoutSeconds: 120,
        );
        $removedPayload = json_decode(trim($removed->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        $password = $topology->ssh(
            'gateway',
            "cd {$gatewayCheckout} && orbit vpn-web-ui:change-password ".escapeshellarg('new-password-1234').' --force --json',
            timeoutSeconds: 120,
        );
        $passwordPayload = json_decode(trim($password->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($created->successful())->toBeTrue()
            ->and($createdPayload['success']['data']['client'])->toMatchArray([
                'name' => 'tablet',
                'address' => '10.6.0.8',
                'enabled' => true,
                'kind' => 'admin',
            ])
            ->and($createdPayload['success']['data']['client']['config'])->toContain('PrivateKey')
            ->and($disabled->successful())->toBeTrue()
            ->and($disabledPayload['success']['data']['client'])->toMatchArray([
                'name' => 'tablet',
                'enabled' => false,
                'action' => 'disabled',
            ])
            ->and($enabled->successful())->toBeTrue()
            ->and($enabledPayload['success']['data']['client'])->toMatchArray([
                'name' => 'tablet',
                'enabled' => true,
                'action' => 'enabled',
            ])
            ->and($removed->successful())->toBeTrue()
            ->and($removedPayload['success']['data']['client'])->toBe([
                'name' => 'tablet',
                'action' => 'removed',
            ])
            ->and($password->successful())->toBeTrue()
            ->and($passwordPayload['success']['data']['vpn'])->toBe([
                'password_changed' => true,
                'sessions_invalidated' => true,
            ]);

        $state = vpnCommandGatewayState($topology, $backendPath);

        expect(array_column($state['backend']['clients'], 'name'))->toBe(['laptop'])
            ->and($state['backend'])->toMatchArray([
                'password_changed' => true,
                'password_length' => 17,
                'sessions_invalidated' => true,
            ])
            ->and($state['activity'])->toContain([
                'event' => 'vpn-client:list',
                'effect' => 'read',
                'total' => 1,
            ])
            ->and($state['activity'])->toContain([
                'event' => 'vpn-client:remove',
                'effect' => 'destructive',
                'total' => 1,
            ]);
    } finally {
        $topology->ssh('gateway', 'rm -f '.escapeshellarg($backendPath), timeoutSeconds: 60);
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');
