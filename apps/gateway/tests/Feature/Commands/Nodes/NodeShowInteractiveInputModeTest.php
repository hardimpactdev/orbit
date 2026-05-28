<?php

declare(strict_types=1);

use App\Console\Commands\NodeShowCommand;
use App\Http\Gateway\GatewayApiException;
use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Http\Gateway\Requests\Nodes\ShowNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

#[Signature('node:show
    {name? : Node name to inspect}
    {--json : Output JSON}')]
#[Description('Show node details from the gateway registry')]
class TestableNodeShowCommand extends NodeShowCommand
{
    public static int $promptCalls = 0;

    public static string $promptedName = 'prompted-node';

    protected function isInteractiveInput(): bool
    {
        return ! $this->option('json');
    }

    protected function promptNodeName(): string|GatewayApiException
    {
        self::$promptCalls++;

        return self::$promptedName;
    }
}

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeShowInteractiveRow(array $overrides = []): array
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

function setupShowInteractiveGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeShowInteractiveRow([
        'name' => 'test-gateway',
    ]));
}

function setupShowInteractiveControlCaller(): void
{
    config(['orbit.is_gateway' => false]);
}

function setupShowInteractiveAppCaller(): void
{
    config(['orbit.is_gateway' => false]);
}

/**
 * @return array{exit_code: int, output: string}
 */
function runTestableNodeShowCommand(array $arguments = []): array
{
    TestableNodeShowCommand::$promptCalls = 0;
    TestableNodeShowCommand::$promptedName = 'prompted-node';

    $command = new TestableNodeShowCommand;
    $command->setLaravel(app());

    $input = new ArrayInput($arguments);
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

    $exitCode = $command->run($input, $output);

    return [
        'exit_code' => $exitCode,
        'output' => $output->fetch(),
    ];
}

describe('node:show interactive input mode', function (): void {
    beforeEach(function (): void {
        setupShowInteractiveGatewayCaller();
    });

    it('uses interactive mode when TTY and --json is absent', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow());

        $exitCode = Artisan::call('node:show', [
            'name' => 'app-1',
            '--no-interaction' => false,
        ]);

        expect($exitCode)->toBe(0);
    });

    it('opts out of interactive mode when --json is present', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow());

        $exitCode = Artisan::call('node:show', [
            'name' => 'app-1',
            '--json' => true,
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();
        $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0);
        expect($payload)->toBeArray();
        expect($payload)->toHaveKey('success');
        expect($payload['success'])->toBeArray();
        expect($payload['success'])->toHaveKey('data');
    });

    it('prompts for a finite node selection when name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'default-app']));
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'prompted-node']));

        $result = runTestableNodeShowCommand();

        expect(TestableNodeShowCommand::$promptCalls)->toBe(1)
            ->and($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain('prompted-node');
    });

    it('prompts for a finite node selection instead of using the calling node when name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'prompted-node']));

        $result = runTestableNodeShowCommand();

        expect(TestableNodeShowCommand::$promptCalls)->toBe(1)
            ->and($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain('prompted-node')
            ->and($result['output'])->not->toContain('test-gateway');
    });

    it('surfaces an unavailable prompted node without falling back to open input', function (): void {
        DB::table('nodes')->delete();

        $result = runTestableNodeShowCommand();

        expect(TestableNodeShowCommand::$promptCalls)->toBe(1)
            ->and($result['exit_code'])->not->toBe(0)
            ->and($result['output'])->toContain("Node 'prompted-node' not found or not visible.")
            ->and($result['output'])->not->toContain('Node name is required.')
            ->and($result['output'])->not->toContain('validation_failed');
    });

    it('uses the documented prompt label and forwards the prompted node name', function (): void {
        config(['orbit.is_gateway' => false]);

        DB::table('nodes')->delete();

        LocalGatewaySettings::current()->fill([
            'gateway_url' => 'https://10.6.0.1',
            'ca_pem_path' => '/dev/null',
        ])->save();

        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'nodes' => [
                            [
                                'name' => 'visible-app',
                                'status' => 'active',
                                'platform' => 'ubuntu_24-04',
                                'host' => '10.6.0.7',
                            ],
                        ],
                    ],
                ],
            ], 200),
            ShowNodeRequest::class => MockResponse::make([
                'success' => [
                    'data' => [
                        'node' => [
                            'name' => 'visible-app',
                            'status' => 'active',
                            'platform' => 'ubuntu_24-04',
                            'wireguard_address' => '10.6.0.7',
                            'addresses' => ['wireguard' => '10.6.0.7'],
                            'agent_ide' => ['adapter' => null, 'source' => 'default'],
                            'grants' => ['consuming_nodes' => [], 'serving_nodes' => []],
                        ],
                    ],
                ],
            ], 200),
        ]);

        DataTablePrompt::fake([Key::ENTER]);

        $this->artisan('node:show')
            ->expectsOutputToContain('Node: visible-app')
            ->assertSuccessful();

        $mock->assertSent(fn (ShowNodeRequest $request): bool => $request->name === 'visible-app');
    });

    it('does not prompt when json forces non-interactive mode', function (): void {
        DB::table('nodes')->delete();

        $result = runTestableNodeShowCommand(['--json' => true]);
        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect(TestableNodeShowCommand::$promptCalls)->toBe(0)
            ->and($result['exit_code'])->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('name');
    });

    it('does not prompt when name is supplied', function (): void {
        $result = runTestableNodeShowCommand(['name' => 'test-gateway']);

        expect(TestableNodeShowCommand::$promptCalls)->toBe(0)
            ->and($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain('test-gateway');
    });

    it('forwards for control callers when not on gateway', function (): void {
        setupShowInteractiveControlCaller();

        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'some-app']));

        $exitCode = Artisan::call('node:show', [
            'name' => 'some-app',
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Gateway connection is required');
    });
});
