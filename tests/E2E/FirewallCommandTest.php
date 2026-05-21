<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

it('writes lists and removes firewall intent on a prepared app node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGatewayAppdev)
        ->withCurrentCheckout(roles: ['gateway']);
    $checkout = escapeshellarg($topology->checkout('gateway'));
    $rule = 'e2e-fw-'.strtolower(bin2hex(random_bytes(3)));
    $denyRule = "{$rule}-deny";

    try {
        firewallCommandPrepareAppNode($topology, $checkout);

        $allow = $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:allow ".escapeshellarg($rule).' --node=app-dev-1 --port=49281 --from=10.6.0.0/24 --reason=e2e --json',
            timeoutSeconds: 120,
        );
        $allowPayload = firewallCommandPayload($allow->output());

        expect($allow->successful())->toBeTrue()
            ->and($allowPayload['success']['data']['rule'])->toMatchArray([
                'name' => $rule,
                'node' => 'app-dev-1',
                'action' => 'allow',
                'source' => '10.6.0.0/24',
                'port' => '49281',
                'status' => 'expected',
            ])
            ->and($allowPayload['success']['meta']['backend_enacted'])->toBeFalse()
            ->and($allowPayload['success']['meta']['warnings'][0]['code'])->toBe('firewall_rule.enactment_deferred');

        $deny = $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:deny ".escapeshellarg($denyRule).' --node=app-dev-1 --port=49282 --from=10.6.0.0/24 --reason=e2e --json',
            timeoutSeconds: 120,
        );
        $denyPayload = firewallCommandPayload($deny->output());

        expect($deny->successful())->toBeTrue()
            ->and($denyPayload['success']['data']['rule'])->toMatchArray([
                'name' => $denyRule,
                'node' => 'app-dev-1',
                'action' => 'deny',
                'source' => '10.6.0.0/24',
                'port' => '49282',
                'status' => 'expected',
            ])
            ->and($denyPayload['success']['meta']['backend_enacted'])->toBeFalse()
            ->and($denyPayload['success']['meta']['warnings'][0]['code'])->toBe('firewall_rule.enactment_deferred');

        $list = $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:list --node=app-dev-1 --json",
            timeoutSeconds: 120,
        );
        $rules = firewallCommandPayload($list->output())['success']['data']['rules'];

        expect(array_column($rules, 'name'))->toContain($rule)
            ->and(array_column($rules, 'name'))->toContain($denyRule);

        $missingConsent = $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:remove ".escapeshellarg($rule).' --node=app-dev-1 --json || true',
            timeoutSeconds: 120,
        );
        $missingConsentPayload = firewallCommandPayload($missingConsent->output());

        expect($missingConsentPayload['error']['code'])->toBe('destructive_consent_required');

        $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan tinker --execute=".escapeshellarg('$node = \App\Models\Node::query()->where("name", "app-dev-1")->firstOrFail(); \App\Models\FirewallRule::updateOrCreate(["node_id" => $node->id, "name" => "orbit-public-ssh-deny-v4"], ["direction" => "incoming", "action" => "deny", "source" => "any", "destination" => null, "port" => "22", "protocol" => "tcp", "reason" => "Protected public SSH deny rule.", "source_hash" => hash("sha256", "e2e-protected-public-ssh-deny-v4"), "address_family" => "v4", "interface" => "public", "owner" => "node-security", "protected" => true]); echo "protected";'),
            timeoutSeconds: 120,
        );

        $protectedRemove = $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:remove orbit-public-ssh-deny-v4 --node=app-dev-1 --force --json || true",
            timeoutSeconds: 120,
        );
        $protectedRemovePayload = firewallCommandPayload($protectedRemove->output());

        expect($protectedRemovePayload['error']['code'])->toBe('firewall_rule.protected');

        $remove = $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:remove ".escapeshellarg($rule).' --node=app-dev-1 --force --json',
            timeoutSeconds: 120,
        );
        $removePayload = firewallCommandPayload($remove->output());

        expect($remove->successful())->toBeTrue()
            ->and($removePayload['success']['data']['rule'])->toMatchArray([
                'name' => $rule,
                'node' => 'app-dev-1',
                'status' => 'removed_with_drift',
            ])
            ->and($removePayload['success']['meta']['backend_removed'])->toBeFalse()
            ->and($removePayload['success']['meta']['warnings'][0]['code'])->toBe('firewall_rule.cleanup_deferred');

        $after = $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:list --node=app-dev-1 --json",
            timeoutSeconds: 120,
        );
        $afterRules = firewallCommandPayload($after->output())['success']['data']['rules'];

        expect(array_column($afterRules, 'name'))->not->toContain($rule);
    } finally {
        $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:remove ".escapeshellarg($rule).' --node=app-dev-1 --force --json >/dev/null 2>&1 || true',
            timeoutSeconds: 120,
        );
        $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan firewall:remove ".escapeshellarg($denyRule).' --node=app-dev-1 --force --json >/dev/null 2>&1 || true',
            timeoutSeconds: 120,
        );
        $topology->ssh(
            'gateway',
            "cd {$checkout} && php artisan tinker --execute=".escapeshellarg('\App\Models\FirewallRule::query()->where("name", "orbit-public-ssh-deny-v4")->delete(); echo "cleaned";').' >/dev/null 2>&1 || true',
            timeoutSeconds: 120,
        );
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway_app-dev', 'e2e-feature-control-gateway-dev');

function firewallCommandPrepareAppNode(E2ETopologyHarness $topology, string $checkout): void
{
    $topology->ssh(
        'gateway',
        "cd {$checkout} && php artisan tinker --execute=".escapeshellarg('$node = \App\Models\Node::query()->where("name", "app-dev-1")->firstOrFail(); $node->update(["platform" => "ubuntu", "status" => "active"]); echo "prepared";'),
        timeoutSeconds: 120,
    );
}

/**
 * @return array<string, mixed>
 */
function firewallCommandPayload(string $output): array
{
    return json_decode(trim($output), associative: true, flags: JSON_THROW_ON_ERROR);
}
