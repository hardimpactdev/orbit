<?php

declare(strict_types=1);

use App\Console\Commands\NodeDefaultCommand;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
    MockClient::destroyGlobal();
});

afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeDefaultHumanRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function invokeNodeDefaultFailCommandHuman(string $code, string $message, array $meta): array
{
    $command = new NodeDefaultCommand;
    $command->setLaravel(app());
    $input = new ArrayInput([]);
    $output = new BufferedOutput;

    $merge = new ReflectionMethod($command, 'mergeApplicationDefinition');
    $merge->setAccessible(true);
    $merge->invoke($command);

    $definition = $command->getDefinition();
    $input->bind($definition);
    $input->validate();

    $init = new ReflectionMethod($command, 'initialize');
    $init->setAccessible(true);
    $init->invoke($command, $input, $output);

    $inputProp = (new ReflectionClass(Command::class))->getProperty('input');
    $inputProp->setAccessible(true);
    $inputProp->setValue($command, $input);

    $outputProp = (new ReflectionClass(Command::class))->getProperty('output');
    $outputProp->setAccessible(true);
    $outputProp->setValue($command, $output);

    $method = new ReflectionMethod($command, 'failCommand');
    $method->setAccessible(true);
    $exitCode = $method->invoke($command, $code, $message, $meta);

    return [
        'exitCode' => $exitCode,
        'output' => $output->fetch(),
    ];
}

/**
 * @param  array<string, mixed>|string  $nodeListBody
 */
function fakeNodeDefaultHumanGateway(array|string $nodeListBody, int $nodeListStatus = 200): void
{
    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();

    MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make(nodeDefaultHumanIdentityEnvelope(), 200),
        ListNodesRequest::class => MockResponse::make($nodeListBody, $nodeListStatus),
    ]);
}

function nodeDefaultHumanIdentityEnvelope(): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => 'control-1',
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                ],
            ],
        ],
    ];
}

describe('node:default human renderer contract', function (): void {
    it('selects human renderer when --json is absent', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('renders progress tree for set sub-action with tree characters', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());

        \Pest\Laravel\artisan('node:default', ['name' => 'app-1'])
            ->expectsOutputToContain('┌  Set Default Node')
            ->expectsOutputToContain('○  Load visible development app nodes')
            ->expectsOutputToContain('○  Store local default')
            ->expectsOutputToContain('└  Default development app node set to app-1')
            ->assertSuccessful();
    });

    it('does not render trailing period on set tree footer', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());

        $exitCode = Artisan::call('node:default', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('└  Default development app node set to app-1');
        expect($output)->not->toContain('└  Default development app node set to app-1.');
    });

    it('does not render progress tree for show sub-action', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('┌')
            ->and($output)->not->toContain('└')
            ->and($output)->not->toContain('○');
    });

    it('does not render progress tree for clear sub-action', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:default', ['--clear' => true]);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('┌')
            ->and($output)->not->toContain('└')
            ->and($output)->not->toContain('○');
    });

    it('renders show success prose with default', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Pest\Laravel\artisan('node:default', ['--no-interaction' => true])
            ->expectsOutputToContain('Default development app node: app-1')
            ->assertSuccessful();
    });

    it('renders show empty-state prose when no default is set', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());

        \Pest\Laravel\artisan('node:default', ['--no-interaction' => true])
            ->expectsOutputToContain('No default development app node is set.')
            ->expectsOutputToContain('Run `orbit node:default <name>` to set one.')
            ->assertSuccessful();
    });

    it('renders set confirmation prose', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());

        \Pest\Laravel\artisan('node:default', ['name' => 'app-1'])
            ->expectsOutputToContain('Default development app node set to app-1')
            ->assertSuccessful();
    });

    it('renders clear-with-default prose', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());
        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'app-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Pest\Laravel\artisan('node:default', ['--clear' => true])
            ->expectsOutputToContain('Default development app node cleared.')
            ->assertSuccessful();
    });

    it('renders clear-no-default prose', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());

        \Pest\Laravel\artisan('node:default', ['--clear' => true])
            ->expectsOutputToContain('No default development app node was set.')
            ->assertSuccessful();
    });

    it('renders mutually exclusive input prose error', function (): void {
        createTestAppHostNode(nodeDefaultHumanRow());

        \Pest\Laravel\artisan('node:default', ['name' => 'app-1', '--clear' => true])
            ->expectsOutputToContain('Cannot provide both a node name and --clear.')
            ->doesntExpectOutputToContain('Provide only one node target.')
            ->assertFailed();
    });

    it('renders node-not-found prose error', function (): void {
        \Pest\Laravel\artisan('node:default', ['name' => 'missing'])
            ->expectsOutputToContain("Node 'missing' not found or not visible.")
            ->assertFailed();
    });

    it('renders not-a-development-app-node prose error with two lines', function (): void {
        DB::table('nodes')->insert(nodeDefaultHumanRow([
            'name' => 'gateway-1',
        ]));

        $exitCode = Artisan::call('node:default', ['name' => 'gateway-1']);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Node 'gateway-1' is not a development app node.");
        expect($output)->toContain('Only development app nodes may be set as the local default.');
    });

    it('renders gateway-unavailable prose error', function (): void {
        $result = invokeNodeDefaultFailCommandHuman(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to set a default node.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Gateway connection is required to set a default node.');
    });

    it('renders authorization-failed prose error', function (): void {
        $result = invokeNodeDefaultFailCommandHuman(
            code: 'authorization_failed',
            message: "This node is not authorized for 'node:read' on 'gateway-1'.",
            meta: [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:read',
                'serving_node' => 'gateway-1',
            ],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain("This node is not authorized for 'node:read' on 'gateway-1'.");
    });

    it('renders gateway authorization failures without collapsing the message', function (): void {
        fakeNodeDefaultHumanGateway([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'Peer identity unknown.',
                'meta' => [],
            ],
        ], 403);

        \Pest\Laravel\artisan('node:default', ['name' => 'app-1'])
            ->expectsOutputToContain('Peer identity unknown.')
            ->doesntExpectOutputToContain('Gateway connection is required to set a default node.')
            ->assertExitCode(1);
    });

    it('renders gateway validation failures without collapsing the message', function (): void {
        fakeNodeDefaultHumanGateway([
            'error' => [
                'code' => 'validation_failed',
                'message' => 'node:default is not supported on gateway nodes.',
                'meta' => [
                    'reason' => 'not_supported_on_gateway',
                ],
            ],
        ], 422);

        \Pest\Laravel\artisan('node:default', ['name' => 'app-1'])
            ->expectsOutputToContain('node:default is not supported on gateway nodes.')
            ->doesntExpectOutputToContain('Gateway connection is required to set a default node.')
            ->assertExitCode(1);
    });

    it('renders gateway_unavailable for gateway failures without a structured error code', function (): void {
        fakeNodeDefaultHumanGateway('Service Unavailable', 503);

        \Pest\Laravel\artisan('node:default', ['name' => 'app-1'])
            ->expectsOutputToContain('Gateway connection is required to set a default node.')
            ->assertExitCode(1);
    });
});
