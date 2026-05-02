<?php

declare(strict_types=1);

use App\Console\Commands\NodeShowCommand;
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
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
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
    it('selects human renderer when --json is absent', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('"success"')
            ->and($output)->not->toContain('"error"');
    });

    it('does not render a progress tree', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('┌')
            ->and($output)->not->toContain('└')
            ->and($output)->not->toContain('│')
            ->and($output)->not->toContain('○');
    });

    it('renders registry success output with all fields for app node', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Node: app-1')
            ->and($output)->toContain('Role: app')
            ->and($output)->toContain('Environment: development')
            ->and($output)->toContain('Platform: ubuntu_24-04')
            ->and($output)->toContain('WireGuard: 10.6.0.7')
            ->and($output)->toContain('Grants:')
            ->and($output)->toContain('Consuming: (none)')
            ->and($output)->toContain('Serving: (none)');
    });

    it('omits environment line for non-app roles', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:show', ['name' => 'gateway-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Node: gateway-1')
            ->and($output)->toContain('Role: gateway')
            ->and($output)->not->toContain('Environment:')
            ->and($output)->toContain('Platform: ubuntu_24-04')
            ->and($output)->toContain('WireGuard: 10.6.0.7');
    });

    it('renders grants section with (none) when grants are empty', function (): void {
        DB::table('nodes')->insert(nodeShowHumanRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Grants:')
            ->and($output)->toContain('Consuming: (none)')
            ->and($output)->toContain('Serving: (none)');
    });

    it('renders missing node prose error', function (): void {
        $exitCode = Artisan::call('node:show', ['name' => 'missing-node']);
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain("Node 'missing-node' not found or not visible.");
    });

    it('renders missing name prose error when no local node exists', function (): void {
        $exitCode = Artisan::call('node:show');
        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Node name is required.');
    });

    it('renders not authorized prose error', function (): void {
        $result = invokeNodeShowFailCommandHuman(
            code: 'authorization_failed',
            message: "This node is not authorized to inspect 'app-1'.",
            meta: ['name' => 'app-1', 'caller_role' => 'control'],
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

    it('renders local context invalid prose error', function (): void {
        $result = invokeNodeShowFailCommandHuman(
            code: 'local_context_invalid',
            message: 'Local node role setting is invalid.',
            meta: ['setting' => 'general.local_node_role', 'reason' => 'unsupported_value', 'caller_role' => 'unknown'],
        );

        expect($result['exitCode'])->not->toBe(0);
        expect($result['output'])->toContain('Local node role setting is invalid.');
    });
});
