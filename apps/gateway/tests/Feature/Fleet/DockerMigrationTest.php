<?php

declare(strict_types=1);

use App\Actions\Fleet\MigrateDockerAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Process as OrbitProcess;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\Fleet\FleetTestSsh;
use Tests\Support\Fleet\ScriptedSshExecutor;
use Tests\Support\ProcessesApiFakeRuntimeManager;

it('requires explicit Docker migration consent before any SSH', function (): void {
    $ssh = new ScriptedSshExecutor;
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));

    expect(Artisan::call('node:migrate-docker', ['node' => 'Beast']))->toBe(1)
        ->and(Artisan::output())->toContain('--yes is required')
        ->and($ssh->commands)->toBe([]);
});

it('refuses a Docker migration recipe mismatch before any SSH', function (): void {
    $ssh = new ScriptedSshExecutor;
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));

    expect(Artisan::call('node:migrate-docker', ['node' => 'Beast', '--yes' => true, '--recipe' => 'sha256:other']))->toBe(1)
        ->and(Artisan::output())->toContain('differs from this checkout')
        ->and($ssh->commands)->toBe([]);
});

it('translates the Docker CE recipe refusal to a typed reason with the explicit migration command', function (): void {
    $node = FleetFixtures::node('ce-provider', [RoleName::AppProd]);
    $ssh = new ScriptedSshExecutor;
    $ssh->on('/^python3 - inspect /', ScriptedSshExecutor::fail(stdout: 'recipe-stage:docker-provider:docker-ce'));
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));
    $recipe = new RecipeLoader()->load();
    $program = app(NodeRecipeProgram::class);

    try {
        $program->inspect($node, $recipe, $program->tools($node, $recipe));
        $this->fail('Docker CE must refuse recipe convergence.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('node.recipe_docker_provider')
            ->and($exception->getMessage())->toContain('docker-provider:docker-ce', 'node:migrate-docker '.$node->id.' --dry-run');
    }
    expect($ssh->commands)->toHaveCount(1)
        ->and($node->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
});

it('runs the Docker dry-run as one read-only remote plan without update locks', function (): void {
    $node = FleetFixtures::node('docker-plan', [RoleName::AppProd]);
    $ssh = new ScriptedSshExecutor;
    $ssh->on('/python3 - plan /', static function (RemoteCommand $command): CommandResult {
        $payload = json_decode($command->arguments[8], true, flags: JSON_THROW_ON_ERROR);
        expect($payload['docker_version'])->toBe(new RecipeLoader()->load()->tools['docker.io']->version)
            ->and($payload['process_ids'])->toBe([])
            ->and($command->input)->toContain('def migration_main():', 'def backup_apt_source(');

        return ScriptedSshExecutor::ok('{"steps":["inventory","stop all containers"],"containers":["mailpit"]}');
    });
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));

    expect(Artisan::call('node:migrate-docker', ['node' => $node->name, '--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('mailpit')
        ->and($ssh->commands)->toHaveCount(1);
});

it('reports final states after reconciling stored specs even when a Process fails', function (bool $failConverge): void {
    $node = FleetFixtures::node('docker-specs', [RoleName::AppProd]);
    $other = FleetFixtures::node('docker-other', [RoleName::AppProd]);
    $attributes = ['owner_type' => $node->getMorphClass(), 'owner_id' => $node->id, 'name' => 'mysql',
        'runtime' => 'docker', 'runtime_config' => ['image' => 'mysql:9', 'environment' => ['PASSWORD' => 'private']],
        'restart_policy' => 'always', 'desired_state' => 'running', 'status' => 'active'];
    $process = OrbitProcess::query()->create($attributes);
    OrbitProcess::query()->create([...$attributes, 'owner_id' => $other->id]);
    $runtime = new ProcessesApiFakeRuntimeManager;
    $runtime->failConverge = $failConverge;
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $ssh = new ScriptedSshExecutor;
    $ssh->on('/python3 - prepare /', static function (RemoteCommand $command) use ($process): CommandResult {
        $payload = json_decode($command->arguments[8], true, flags: JSON_THROW_ON_ERROR);
        expect($payload['process_ids'])->toBe([(string) $process->id])
            ->and($payload['process_states'][(string) $process->id])->toBe('running')
            ->and($payload['process_specs'][(string) $process->id]['hash'])->toBeString()
            ->and($command->arguments)->not->toContain('private');

        return ScriptedSshExecutor::ok(json_encode(['fallback' => true, 'process_ids' => [(string) $process->id], 'containers' => []], JSON_THROW_ON_ERROR));
    });
    $ssh->on('/python3 - report /', static function () use ($runtime, $process): CommandResult {
        expect($runtime->convergedProcessIds)->toBe([$process->id]);

        return ScriptedSshExecutor::ok('{"healthy":true,"containers":[{"name":"mysql","state":"running"}]}');
    });
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));
    app()->instance(NodeUpdateLock::class, new NodeUpdateLock(FleetTestSsh::shell($ssh)));

    $report = app(MigrateDockerAction::class)->execute($node, new RecipeLoader()->load(), false);

    expect($report['healthy'])->toBe(! $failConverge)->and($runtime->convergedProcessIds)->toBe([$process->id]);
    if ($failConverge) {
        expect($report['failed_process_ids'])->toBe([$process->id]);
    }
})->with(['success' => false, 'Process failure' => true]);

it('keeps Docker migration remote diagnostics private and stops before Process reconciliation', function (): void {
    $node = FleetFixtures::node('docker-failure', [RoleName::AppProd]);
    $ssh = new ScriptedSshExecutor;
    $ssh->on('/python3 - prepare /', ScriptedSshExecutor::fail(stdout: '{"failure":"docker-migration:package-verification"}', stderr: 'PASSWORD=private'));
    app()->instance(NodeShell::class, FleetTestSsh::shell($ssh));
    app()->instance(NodeUpdateLock::class, new NodeUpdateLock(FleetTestSsh::shell($ssh)));
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);

    expect(Artisan::call('node:migrate-docker', ['node' => $node->name, '--yes' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('docker-migration:package-verification', 'Resume')
        ->not->toContain('PASSWORD=private')
        ->and($runtime->convergedProcessIds)->toBe([]);
});

it('executes the Docker provider inventory swap fallback preservation and resume regression programs', function (): void {
    $process = new Process(['python3', base_path('tests/Programs/test_docker_migration.py'), '-v']);
    $process->mustRun();

    expect($process->getErrorOutput())->toContain('Ran 17 tests', 'OK');
});
