<?php

declare(strict_types=1);

use App\Console\Commands\NodeGrantCommand;
use App\Http\Gateway\Requests\Gateway\ShowGatewayIdentityRequest;
use App\Http\Gateway\Requests\Nodes\GrantNodeRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

beforeEach(fn (): null => MockClient::destroyGlobal());
afterEach(fn (): null => MockClient::destroyGlobal());

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeGrantControlContractRow(array $overrides = []): array
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

function setupNodeGrantControlCaller(): void
{
    config(['orbit.is_gateway' => false]);

    DB::table('nodes')->insert(nodeGrantControlContractRow([
        'name' => 'control-1',
    ]));

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_pem_path' => '/tmp/fake-orbit-ca.pem',
    ])->save();
}

function nodeGrantControlIdentityEnvelope(): array
{
    return [
        'success' => [
            'data' => [
                'self' => [
                    'name' => 'control-1',
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.8'],
                ],
                'gateway' => [
                    'name' => 'gateway-1',
                    'status' => 'active',
                    'platform' => 'unknown',
                    'addresses' => ['wireguard' => '10.6.0.2'],
                ],
            ],
        ],
    ];
}

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeNodeGrantControlGateway(array|string $body, int $status = 200): MockClient
{
    return MockClient::global([
        ShowGatewayIdentityRequest::class => MockResponse::make(nodeGrantControlIdentityEnvelope(), 200),
        GrantNodeRequest::class => MockResponse::make($body, $status),
    ]);
}

describe('node:grant on operator node contract', function (): void {
    it('forwards configured control-node grants to the gateway without local target rows', function (): void {
        setupNodeGrantControlCaller();

        $mock = fakeNodeGrantControlGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => false,
                    'permissions' => ['app:read', 'node:read'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'action' => 'granted',
                'already_granted' => false,
                'permissions' => ['app:read', 'node:read'],
            ])
            ->and(DB::table('nodes')->where('name', 'app-1')->exists())->toBeFalse()
            ->and(DB::table('node_access')->count())->toBe(0);

        $mock->assertSent(fn (GrantNodeRequest $request): bool => $request->resolveEndpoint() === '/api/nodes/grant'
            && $request->body()->all() === [
                'consuming_node' => 'control-1',
                'serving_node' => 'app-1',
                'preset' => 'operator',
            ]);
    });

    it('renders forwarded already-granted success with human output', function (): void {
        setupNodeGrantControlCaller();

        fakeNodeGrantControlGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => true,
                    'permissions' => ['app:read', 'node:read'],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
        ]);

        expect($exitCode)->toBe(0);
        expect(Artisan::output())->toContain("'control-1' already has access to 'app-1'");
    });

    it('fails locally when missing preset or permissions on operator node with json', function (): void {
        setupNodeGrantControlCaller();

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Use --preset or --permissions to specify grant permissions.')
            ->and($payload['error']['meta'])->toBe(['fields' => ['preset', 'permissions']]);
    });

    it('fails when gateway response omits required permissions', function (): void {
        setupNodeGrantControlCaller();

        fakeNodeGrantControlGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => false,
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_response_invalid')
            ->and($payload['error']['message'])->toBe('Gateway response missing required permissions field.');
    });

    it('forwards interactive prompt permissions to the gateway as comma-separated string', function (): void {
        setupNodeGrantControlCaller();

        $mock = fakeNodeGrantControlGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => false,
                    'permissions' => ['node:read', 'tool:read'],
                ],
            ],
        ]);

        $command = new NodeGrantCommand;
        $command->setLaravel(app());

        $input = new ArrayInput([
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
        ]);
        $output = new BufferedOutput;

        $definition = $command->getDefinition();
        if (! $definition->hasArgument('consuming_node')) {
            $definition->addArgument(new InputArgument('consuming_node', InputArgument::REQUIRED));
            $definition->addArgument(new InputArgument('serving_node', InputArgument::REQUIRED));
            $definition->addOption(new InputOption('preset', null, InputOption::VALUE_OPTIONAL));
            $definition->addOption(new InputOption('permissions', null, InputOption::VALUE_OPTIONAL));
            $definition->addOption(new InputOption('force', null, InputOption::VALUE_NONE));
            $definition->addOption(new InputOption('json', null, InputOption::VALUE_NONE));
        }
        $input->bind($definition);
        $input->validate();

        $inputProp = (new ReflectionClass(Command::class))->getProperty('input');
        $inputProp->setAccessible(true);
        $inputProp->setValue($command, $input);

        $outputProp = (new ReflectionClass(Command::class))->getProperty('output');
        $outputProp->setAccessible(true);
        $outputProp->setValue($command, $output);

        $sendMethod = new ReflectionMethod($command, 'sendForwardGrantRequest');
        $sendMethod->setAccessible(true);
        $exitCode = $sendMethod->invoke($command, 'control-1', 'app-1', null, null, ['node:read', 'tool:read'], true, false);

        expect($exitCode)->toBe(0);

        $mock->assertSent(fn (GrantNodeRequest $request): bool => $request->body()->all() === [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            'permissions' => 'node:read,tool:read',
        ]);
    });

    it('preserves structured gateway errors when forwarding', function (array $error): void {
        setupNodeGrantControlCaller();

        fakeNodeGrantControlGateway(['error' => $error], 422);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--preset' => 'operator',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error'])->toBe($error);
    })->with([
        'authorization failure' => [[
            'code' => 'authorization_failed',
            'message' => 'This action requires the node:grant permission on a grant to the gateway.',
            'meta' => [
                'reason' => 'missing_permission',
                'missing_permission' => 'node:grant',
                'serving_node' => 'gateway-1',
            ],
        ]],
        'not found' => [[
            'code' => 'node.not_found',
            'message' => "Serving node 'app-1' not found.",
            'meta' => [
                'field' => 'serving_node',
                'name' => 'app-1',
            ],
        ]],
        'policy violation' => [[
            'code' => 'node.grant_policy_violation',
            'message' => 'A node cannot be granted access to itself.',
            'meta' => [
                'consuming_node' => 'control-1',
                'serving_node' => 'control-1',
                'reason' => 'self_grant',
            ],
        ]],
    ]);

    it('preserves gateway warning metadata in forwarded --json success responses', function (): void {
        setupNodeGrantControlCaller();

        fakeNodeGrantControlGateway([
            'success' => [
                'data' => [
                    'consuming_node' => 'control-1',
                    'serving_node' => 'app-1',
                    'action' => 'granted',
                    'already_granted' => false,
                    'permissions' => ['node:read', 'node:list'],
                ],
                'meta' => [
                    'warnings' => [
                        [
                            'code' => 'node.redundant_permissions',
                            'family' => 'node',
                            'message' => 'Redundant permissions were removed: node:list.',
                            'next_command' => null,
                            'permissions' => ['node:list'],
                        ],
                    ],
                ],
            ],
        ]);

        $exitCode = Artisan::call('node:grant', [
            'consuming_node' => 'control-1',
            'serving_node' => 'app-1',
            '--permissions' => 'node:read,node:list',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['permissions'])->toBe(['node:read', 'node:list'])
            ->and($payload['success']['meta']['warnings'])->toBe([
                [
                    'code' => 'node.redundant_permissions',
                    'family' => 'node',
                    'message' => 'Redundant permissions were removed: node:list.',
                    'next_command' => null,
                    'permissions' => ['node:list'],
                ],
            ]);
    });
});
