<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Firewall\RemoveFirewallRuleRequest;
use App\Http\Gateway\Requests\Firewall\StoreFirewallRuleRequest;
use App\Models\FirewallRule;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createFirewallMutationLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'platform' => 'ubuntu',
        'is_local' => true,
    ]);
}

describe('firewall mutation commands', function (): void {
    it('stores allow and deny intent on gateway callers', function (string $command, string $action): void {
        createFirewallMutationLocalNode('gateway');
        Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);

        $exitCode = Artisan::call($command, [
            'name' => "rule-{$action}",
            '--json' => true,
            '--node' => 'app-1',
            '--port' => '5173',
            '--from' => '10.6.0.0/24',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['rule']['action'])->toBe($action)
            ->and($payload['success']['meta']['warnings'][0]['code'])->toBe('firewall_rule.enactment_deferred')
            ->and(FirewallRule::query()->where('name', "rule-{$action}")->exists())->toBeTrue();
    })->with([
        'allow' => ['firewall:allow', 'allow'],
        'deny' => ['firewall:deny', 'deny'],
    ]);

    it('renders the raw store progress tree before the success prose', function (): void {
        createFirewallMutationLocalNode('gateway');
        Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);

        $exitCode = Artisan::call('firewall:allow', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--port' => '5173',
            '--from' => '10.6.0.0/24',
        ]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌  Managing Firewall Rule')
            ->and($output)->toContain('○  Validate firewall target')
            ->and($output)->toContain('●  Apply and verify firewall rule')
            ->and($output)->toContain('└  Firewall rule intent saved.')
            ->and($output)->toContain("Firewall rule 'local-vite' saved on node 'app-1'.");
    });

    it('renders the decorated remove progress tree with ansi state dots', function (): void {
        createFirewallMutationLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $output = new BufferedOutput(decorated: true);
        $exitCode = Artisan::call('firewall:remove', [
            'name' => 'local-vite',
            '--node' => 'app-1',
            '--force' => true,
        ], $output);
        $text = $output->fetch();

        expect($exitCode)->toBe(0);
        expect($text)->toContain("\e[38;5;242m┌\e[39m  \e[97mRemoving Firewall Rule\e[39m")
            ->and($text)->toContain("\e[32m●\e[39m")
            ->and($text)->toContain('Working...')
            ->and($text)->toContain("Firewall rule 'local-vite' removed from node 'app-1'.");
    });

    it('forwards non-gateway store calls through the typed gateway request', function (): void {
        createFirewallMutationLocalNode('control');
        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            StoreFirewallRuleRequest::class => MockResponse::make([
                'success' => [
                    'data' => ['rule' => ['name' => 'local-vite', 'node' => 'app-1', 'status' => 'expected']],
                    'meta' => ['warnings' => []],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('firewall:allow', [
            'name' => 'local-vite',
            '--json' => true,
            '--node' => 'app-1',
            '--port' => '5173',
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['rule']['name'])->toBe('local-vite');
    });

    it('requires destructive consent for remove and then removes intent', function (): void {
        createFirewallMutationLocalNode('gateway');
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app', 'platform' => 'ubuntu']);
        FirewallRule::factory()->create(['node_id' => $node->id, 'name' => 'local-vite']);

        $missingConsent = Artisan::call('firewall:remove', ['name' => 'local-vite', '--json' => true, '--node' => 'app-1']);
        $error = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $removed = Artisan::call('firewall:remove', ['name' => 'local-vite', '--json' => true, '--node' => 'app-1', '--force' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($missingConsent)->toBe(1)
            ->and($error['error']['code'])->toBe('destructive_consent_required')
            ->and($removed)->toBe(0)
            ->and($payload['success']['data']['rule']['status'])->toBe('removed_with_drift')
            ->and(FirewallRule::query()->where('name', 'local-vite')->exists())->toBeFalse();
    });

    it('forwards non-gateway remove calls through the typed gateway request', function (): void {
        createFirewallMutationLocalNode('control');
        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        MockClient::global([
            RemoveFirewallRuleRequest::class => MockResponse::make([
                'success' => [
                    'data' => ['rule' => ['name' => 'local-vite', 'node' => 'app-1', 'status' => 'removed_with_drift']],
                    'meta' => ['warnings' => []],
                ],
            ], 200),
        ]);

        $exitCode = Artisan::call('firewall:remove', [
            'name' => 'local-vite',
            '--json' => true,
            '--node' => 'app-1',
            '--force' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['rule']['status'])->toBe('removed_with_drift');
    });
});
