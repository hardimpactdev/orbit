<?php

declare(strict_types=1);

use App\Actions\Processes\RestartProcesses;
use App\Actions\Processes\StartProcesses;
use App\Actions\Processes\StopProcesses;
use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntimeStatus;
use App\Enums\ProcessEventType;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Services\Processes\ProcessOwnerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{context: ProcessOwnerContext, process: Process}
 */
function lifecycleTransitionFixture(): array
{
    $node = createTestAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'vite',
        ]);

    return [
        'context' => new ProcessOwnerContext(
            node: $node,
            app: $app,
            workspace: null,
            owner: $app,
            instance: $instance,
        ),
        'process' => $process,
    ];
}

/**
 * @param  list<RemoteShellResult>|\RuntimeException  $results
 */
function bindLifecycleRemoteShell(array|\RuntimeException $results): void
{
    if ($results instanceof \RuntimeException) {
        app()->instance(RemoteShell::class, new class($results) implements RemoteShell {
            public function __construct(
                private \RuntimeException $exception,
            ) {}

            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                throw $this->exception;
            }
        });

        return;
    }

    app()->instance(RemoteShell::class, new class($results) implements RemoteShell {
        /** @param  list<RemoteShellResult>  $results */
        public function __construct(
            private array $results,
        ) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return (
                array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1)
            );
        }
    });
}

function eventTypeValues(): array
{
    return ProcessEvent::query()
        ->orderBy('id')
        ->pluck('event')
        ->map(
            static fn (ProcessEventType $type): string => $type->value,
        )
        ->all();
}

it('records starting then started for a successful start', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $result = app(StartProcesses::class)->handle($fixture['context'], 'vite');

    expect($result['failed'])
        ->toBeFalse()
        ->and(eventTypeValues())
        ->toBe(['starting', 'started'])
        ->and($result['data']['runtimes'][0]['events'][0]['type'])
        ->toBe('starting')
        ->and($result['data']['runtimes'][0]['events'][1]['type'])
        ->toBe('started')
        ->and(ProcessRuntimeStatus::fromEventType(ProcessEventType::Started)->value)
        ->toBe('running');
});

it('records starting then failed when start returns false so status is unknown', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
    ]);

    $result = app(StartProcesses::class)->handle($fixture['context'], 'vite');

    expect($result['failed'])
        ->toBeTrue()
        ->and(eventTypeValues())
        ->toBe(['starting', 'failed'])
        ->and(ProcessRuntimeStatus::fromEventType(ProcessEventType::Failed)->value)
        ->toBe('unknown')
        ->and(ProcessRuntimeStatus::fromEventType(ProcessEventType::Starting)->value)
        ->toBe('starting');
});

it('records failed and rethrows when the start driver throws', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell(new \RuntimeException('backend boom'));

    expect(fn () => app(StartProcesses::class)->handle($fixture['context'], 'vite'))
        ->toThrow(\RuntimeException::class, 'backend boom');

    expect(eventTypeValues())
        ->toBe(['starting', 'failed'])
        ->and(ProcessRuntimeStatus::fromEventType(
            ProcessEvent::query()->latest('id')->firstOrFail()->event,
        )->value)
        ->toBe('unknown');
});

it('records stopping then stopped for a successful stop', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    app(StopProcesses::class)->handle($fixture['context'], 'vite');

    expect(eventTypeValues())->toBe(['stopping', 'stopped']);
});

it('records stopping then failed when stop returns false', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
    ]);

    $result = app(StopProcesses::class)->handle($fixture['context'], 'vite');

    expect($result['failed'])->toBeTrue()->and(eventTypeValues())->toBe(['stopping', 'failed']);
});

it('records failed and rethrows when the stop driver throws', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell(new \RuntimeException('stop boom'));

    expect(fn () => app(StopProcesses::class)->handle($fixture['context'], 'vite'))
        ->toThrow(\RuntimeException::class, 'stop boom');

    expect(eventTypeValues())->toBe(['stopping', 'failed']);
});

it('records restarting then started for a successful restart', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    app(RestartProcesses::class)->handle($fixture['context'], 'vite');

    expect(eventTypeValues())->toBe(['restarting', 'started']);
});

it('records restarting then failed when restart returns false', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
    ]);

    $result = app(RestartProcesses::class)->handle($fixture['context'], 'vite');

    expect($result['failed'])->toBeTrue()->and(eventTypeValues())->toBe(['restarting', 'failed']);
});

it('records failed and rethrows when the restart driver throws', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell(new \RuntimeException('restart boom'));

    expect(fn () => app(RestartProcesses::class)->handle($fixture['context'], 'vite'))
        ->toThrow(\RuntimeException::class, 'restart boom');

    expect(eventTypeValues())->toBe(['restarting', 'failed']);
});

it('never leaves latest status stuck on a transitional event after false failure', function (): void {
    $fixture = lifecycleTransitionFixture();
    bindLifecycleRemoteShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1),
    ]);

    app(StartProcesses::class)->handle($fixture['context'], 'vite');

    $latest = ProcessEvent::query()->latest('id')->firstOrFail();

    expect($latest->event)
        ->toBe(ProcessEventType::Failed)
        ->and(ProcessRuntimeStatus::fromEventType($latest->event)->isTransitional())
        ->toBeFalse()
        ->and(ProcessRuntimeStatus::fromEventType($latest->event)->value)
        ->toBe('unknown');
});
