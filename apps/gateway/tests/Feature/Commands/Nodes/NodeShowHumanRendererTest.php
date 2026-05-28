<?php

declare(strict_types=1);

use App\Console\Commands\NodeShowCommand;
use App\Models\NodeRoleAssignment;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeShowHumanRow(array $overrides = []): array
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

function setupNodeShowHumanGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeShowHumanRow([
        'name' => 'local-gateway',
    ]));
}

/**
 * @param  array<string, mixed>  $settings
 */
function assignNodeShowHumanRole(string $nodeName, string $role, array $settings = []): void
{
    $nodeId = (int) DB::table('nodes')
        ->where('name', $nodeName)
        ->value('id');

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function invokeNodeShowFailCommandHuman(string $code, string $message, array $meta): array
{
    $command = new NodeShowCommand;
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

describe('node:show human renderer contract', function (): void {
    beforeEach(function (): void {
        setupNodeShowHumanGatewayCaller();
    });

    it('selects human renderer when --json is absent', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('renders a detail tree without progress markers', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('┌  Node: app-1')
            ->and($output)->toContain('└  Consuming')
            ->and($output)->not->toContain('○');
    });

    it('dims decorated detail tree connector glyphs only', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $output = new BufferedOutput(decorated: true);
        $exitCode = Artisan::call('node:show', ['name' => 'app-1'], $output);
        $buffer = $output->fetch();
        $plainBuffer = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $buffer) ?? $buffer;

        expect($exitCode)->toBe(0)
            ->and($plainBuffer)->toContain('┌  Node: app-1')
            ->and($plainBuffer)->toContain('├  Role')
            ->and($plainBuffer)->toContain('│')
            ->and($plainBuffer)->toContain('└  Consuming')
            ->and($buffer)->toContain("\e[38;5;242m┌\e[39m")
            ->and($buffer)->toContain("\e[38;5;242m├\e[39m")
            ->and($buffer)->toContain("\e[38;5;242m│\e[39m")
            ->and($buffer)->toContain("\e[38;5;242m└\e[39m")
            ->and($buffer)->not->toContain("\e[38;5;242mNode: app-1")
            ->and($buffer)->not->toContain("\e[38;5;242mRole");
    });

    it('renders spacer rows between detail properties', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toMatch('/├  Role\s+app\n│\n├  OS/')
            ->and($output)->toMatch('/├  Serving\s+—\n│\n└  Consuming\s+—/')
            ->and($output)->not->toMatch('/└  Consuming\s+—\n│/');
    });

    it('frames the detail tree with leading and trailing empty lines', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toMatch('/^\n┌  Node: app-1\n/')
            ->and($output)->toMatch('/└  Consuming\s+—\n\n$/');
    });

    it('renders singular role assignment without legacy role or environment in human output', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());
        assignNodeShowHumanRole('app-1', 'app-dev', ['tld' => 'test']);

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Node: app-1')
            ->and($output)->toContain('Role')
            ->and($output)->toContain('app-dev')
            ->and($output)->not->toContain('Roles')
            ->and($output)->not->toContain('Environment')
            ->and($output)->toContain('OS')
            ->and($output)->toContain('ubuntu_24-04')
            ->and($output)->toContain('Peer IP')
            ->and($output)->toContain('10.6.0.7')
            ->and($output)->toContain('Serving')
            ->and($output)->toContain('Consuming');
    });

    it('omits environment line and falls back to legacy role when assignments are absent', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow([
            'name' => 'gateway-2',
        ]));

        $exitCode = Artisan::call('node:show', ['name' => 'gateway-2']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Node: gateway-2')
            ->and($output)->toContain('Role')
            ->and($output)->toContain('gateway')
            ->and($output)->not->toContain('Environment')
            ->and($output)->toContain('OS')
            ->and($output)->toContain('ubuntu_24-04')
            ->and($output)->toContain('Peer IP')
            ->and($output)->toContain('10.6.0.7');
    });

    it('renders grant rows as empty when grants are empty', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Consuming')
            ->and($output)->toContain('Serving')
            ->and($output)->toContain('—');
    });

    it('renders human grant labels from the target node perspective', function (): void {
        DB::table('nodes')->insert([
            nodeShowHumanRow([
                'name' => 'app-1',
            ]),
            nodeShowHumanRow([
                'name' => 'control-1',
            ]),
            nodeShowHumanRow([
                'name' => 'control-2',
            ]),
        ]);

        $app1Id = DB::table('nodes')->where('name', 'app-1')->value('id');
        $control1Id = DB::table('nodes')->where('name', 'control-1')->value('id');
        $control2Id = DB::table('nodes')->where('name', 'control-2')->value('id');

        DB::table('node_access')->insert([
            ['consumer_node_id' => $control1Id, 'serving_node_id' => $app1Id, 'created_at' => now(), 'updated_at' => now()],
            ['consumer_node_id' => $control2Id, 'serving_node_id' => $app1Id, 'created_at' => now(), 'updated_at' => now()],
            ['consumer_node_id' => $app1Id, 'serving_node_id' => $control1Id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toMatch('/Serving\s+control-1: \*, control-2: \*/')
            ->and($output)->toMatch('/Consuming\s+control-1: \*/');
    });

    it('renders missing node prose error', function (): void {
        $exitCode = Artisan::call('node:show', ['name' => 'missing-node']);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Node 'missing-node' not found or not visible.");
    });

    it('renders missing name prose error when no local node exists', function (): void {
        DB::table('nodes')->delete();

        $exitCode = Artisan::call('node:show', ['--no-interaction' => true]);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Node name is required.');
    });

    it('renders not authorized prose error', function (): void {
        $result = invokeNodeShowFailCommandHuman(
            code: 'authorization_failed',
            message: "This node is not authorized to inspect 'app-1'.",
            meta: ['name' => 'app-1', 'reason' => 'missing_permission', 'missing_permission' => 'node:read'],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain("This node is not authorized to inspect 'app-1'.");
    });

    it('renders gateway unavailable prose error', function (): void {
        $result = invokeNodeShowFailCommandHuman(
            code: 'gateway_unavailable',
            message: 'Gateway connection is required to show node details.',
            meta: [],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Gateway connection is required to show node details.');
    });
});
