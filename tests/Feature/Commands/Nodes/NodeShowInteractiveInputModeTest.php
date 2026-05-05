<?php

declare(strict_types=1);

use App\Console\Commands\NodeShowCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    protected function promptNodeName(string $callerRole): string
    {
        self::$promptCalls++;

        return self::$promptedName;
    }
}

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeShowInteractiveRow(array $overrides = []): array
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

function setupShowInteractiveGatewayCaller(): void
{
    DB::table('nodes')->where('is_local', true)->delete();
    DB::table('nodes')->insert(nodeShowInteractiveRow([
        'name' => 'test-gateway',
        'role' => 'gateway',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupShowInteractiveControlCaller(): void
{
    DB::table('nodes')->where('is_local', true)->delete();
    DB::table('nodes')->insert(nodeShowInteractiveRow([
        'name' => 'test-control',
        'role' => 'control',
        'environment' => null,
        'is_local' => true,
    ]));
}

function setupShowInteractiveAppCaller(): void
{
    DB::table('nodes')->where('is_local', true)->delete();
    DB::table('nodes')->insert(nodeShowInteractiveRow([
        'name' => 'test-app',
        'role' => 'app',
        'environment' => 'development',
        'is_local' => true,
    ]));
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

    it('resolves local default node when set and name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'default-app']));

        DB::table('local_node_defaults')->insert([
            'default_node_name' => 'default-app',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('node:show', [
            '--no-interaction' => false,
        ]);

        expect($exitCode)->toBe(0);
    });

    it('resolves calling node when no default is set and name is missing', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'other-gateway', 'role' => 'gateway']));

        $exitCode = Artisan::call('node:show', [
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('test-gateway');
    });

    it('prompts for name when neither default nor calling node can be resolved', function (): void {
        DB::table('nodes')->delete();
        DB::table('local_node_defaults')->delete();

        $result = runTestableNodeShowCommand();

        expect(TestableNodeShowCommand::$promptCalls)->toBe(1)
            ->and($result['exit_code'])->not->toBe(0)
            ->and($result['output'])->toContain('Gateway connection is required')
            ->and($result['output'])->not->toContain('Node name is required.')
            ->and($result['output'])->not->toContain('validation_failed');
    });

    it('does not prompt when json forces non-interactive mode', function (): void {
        DB::table('nodes')->delete();
        DB::table('local_node_defaults')->delete();

        $result = runTestableNodeShowCommand(['--json' => true]);
        $payload = json_decode($result['output'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect(TestableNodeShowCommand::$promptCalls)->toBe(0)
            ->and($result['exit_code'])->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['meta']['field'])->toBe('name');
    });

    it('does not prompt when the calling node default resolves the target', function (): void {
        $result = runTestableNodeShowCommand();

        expect(TestableNodeShowCommand::$promptCalls)->toBe(0)
            ->and($result['exit_code'])->toBe(0)
            ->and($result['output'])->toContain('test-gateway');
    });

    it('does not deny app callers before prompts or side effects', function (): void {
        setupShowInteractiveAppCaller();

        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'visible-app']));

        $exitCode = Artisan::call('node:show', [
            'name' => 'visible-app',
            '--no-interaction' => false,
        ]);

        $output = Artisan::output();

        expect($exitCode)->not->toBe(0);
        expect($output)->toContain('Gateway');
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

    it('executes locally for gateway callers', function (): void {
        DB::table('nodes')->insert(nodeShowInteractiveRow(['name' => 'local-app']));

        $exitCode = Artisan::call('node:show', [
            'name' => 'local-app',
            '--no-interaction' => false,
        ]);

        expect($exitCode)->toBe(0);
    });
});
