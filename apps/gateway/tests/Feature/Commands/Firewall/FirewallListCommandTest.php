<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Firewall\ListFirewallRulesRequest;
use App\Models\FirewallRule;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

function createFirewallListLocalNode(string $role = 'gateway'): Node
{
    $attributes = [
        'name' => "local-{$role}",
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'platform' => 'ubuntu'];

    if ($role === 'gateway') {
        return createTestGatewayNode($attributes);
    }

    return Node::factory()->create($attributes);
}

describe('firewall:list command contract', function (): void {
    it('lists gateway registry intent as json with metadata', function (): void {
        createFirewallListLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);

        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173',
            'reason' => 'local development server']);

        $exitCode = Artisan::call('firewall:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['rules'][0])->toMatchArray([
                'name' => 'local-vite',
                'node' => 'app-1',
                'direction' => 'incoming',
                'action' => 'allow',
                'source' => '10.6.0.0/24',
                'destination' => null,
                'port' => 5173,
                'protocol' => 'tcp',
                'reason' => 'local development server',
                'status' => 'expected'])
            ->and($payload['success']['meta'])->toBe([
                'node' => null,
                'count' => 1]);
    });

    it('filters by firewall target node', function (): void {
        createFirewallListLocalNode('gateway');
        $firstNode = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
        $secondNode = createTestAppHostNode(['name' => 'app-2', 'platform' => 'ubuntu']);

        FirewallRule::factory()->create(['node_id' => $firstNode->id, 'name' => 'first']);
        FirewallRule::factory()->create(['node_id' => $secondNode->id, 'name' => 'second']);

        $exitCode = Artisan::call('firewall:list', [
            '--json' => true,
            '--node' => 'app-2']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and(array_column($payload['success']['data']['rules'], 'name'))->toBe(['second'])
            ->and($payload['success']['meta'])->toBe([
                'node' => 'app-2',
                'count' => 1]);
    });

    it('renders human tables and empty scope states', function (): void {
        createFirewallListLocalNode('gateway');
        $node = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);

        FirewallRule::factory()->create([
            'node_id' => $node->id,
            'name' => 'local-vite',
            'source' => '10.6.0.0/24',
            'port' => '5173']);

        $this->artisan('firewall:list')
            ->expectsOutput('Node: app-1')
            ->expectsPromptsTable(
                ['Name', 'Direction', 'Action', 'Source', 'Destination', 'Port', 'Protocol', 'Family', 'Interface', 'Owner', 'Reason', 'Status'],
                [['local-vite', 'incoming', 'allow', '10.6.0.0/24', '-', 5173, 'tcp', 'v4', '-', 'user', 'test firewall rule', 'expected']],
            )
            ->assertSuccessful();

        $this->artisan('firewall:list --node=local-gateway')
            ->expectsOutput('No firewall rules found for local-gateway.')
            ->assertSuccessful();
    });

    it('rejects invalid node input before gateway calls', function (): void {
        $exitCode = Artisan::call('firewall:list', ['--json' => true, '--node' => 'one,two']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('node');
    });

    it('forwards non-gateway callers through the typed gateway request', function (): void {
        config(['orbit.is_gateway' => false]);

        createFirewallListLocalNode('control');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null'])->save();

        MockClient::global([
            ListFirewallRulesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'rules' => [
                            [
                                'name' => 'local-vite',
                                'node' => 'app-1',
                                'direction' => 'incoming',
                                'action' => 'allow',
                                'source' => '10.6.0.0/24',
                                'destination' => null,
                                'port' => 5173,
                                'protocol' => 'tcp',
                                'reason' => 'local development server',
                                'status' => 'expected']]],
                    'meta' => [
                        'node' => 'app-1',
                        'count' => 1]]], 200)]);

        $exitCode = Artisan::call('firewall:list', [
            '--json' => true,
            '--node' => 'app-1']);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['rules'][0]['name'])->toBe('local-vite')
            ->and($payload['success']['meta']['node'])->toBe('app-1');
    });

    it('preserves structured gateway authorization failures', function (): void {
        config(['orbit.is_gateway' => false]);

        createFirewallListLocalNode('app');

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null'])->save();

        MockClient::global([
            ListFirewallRulesRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This node is not authorized to read the firewall rule registry.',
                    'meta' => [
                        'reason' => 'missing_permission',
                        'missing_permission' => 'firewall_rule:read']]], 403)]);

        $exitCode = Artisan::call('firewall:list', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('authorization_failed')
            ->and($payload['error']['meta']['missing_permission'])->toBe('firewall_rule:read');
    });

    it('does not mutate firewall registry state or run live probes', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        createFirewallListLocalNode('gateway');
        FirewallRule::factory()->count(2)->create();

        $ruleCount = DB::table('firewall_rules')->count();

        $this->artisan('firewall:list')->assertSuccessful();

        expect(DB::table('firewall_rules')->count())->toBe($ruleCount);
        Process::assertNothingRan();
    });
});
