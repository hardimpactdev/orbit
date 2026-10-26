<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use App\Models\Process as OrbitProcess;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\ProcessesApiFakeRuntimeManager;

it('requires explicit Docker maintenance consent before any agent command', function (bool $restorePolicies): void {
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    expect(Artisan::call('node:migrate-docker', ['node' => 'Beast', '--restore-policies' => $restorePolicies]))->toBe(1)
        ->and(Artisan::output())->toContain('--yes is required')
        ->and($agents->calls)->toBe([]);
})->with([false, true]);

it('refuses policy recovery combined with migration options before any agent command', function (string $option): void {
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    expect(Artisan::call('node:migrate-docker', ['node' => 'Beast', '--yes' => true, '--restore-policies' => true, $option => true]))->toBe(1)
        ->and(Artisan::output())->toContain('--restore-policies cannot be combined')
        ->and($agents->calls)->toBe([]);
})->with(['--dry-run', '--fallback']);

it('refuses a Docker migration recipe mismatch before any agent command', function (): void {
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    expect(Artisan::call('node:migrate-docker', ['node' => 'Beast', '--yes' => true, '--recipe' => 'sha256:other']))->toBe(1)
        ->and(Artisan::output())->toContain('differs from this checkout')
        ->and($agents->calls)->toBe([]);
});

it('translates the Docker CE recipe refusal to a typed reason with the explicit migration command', function (): void {
    $node = FleetFixtures::node('ce-provider', [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    $agents->refuse('recipe.inspect', 'recipe.failed', ['stage' => 'recipe-stage:docker-provider:docker-ce']);
    app()->instance(AgentCommandRunner::class, $agents);
    $recipe = new RecipeLoader()->load();
    $program = app(NodeRecipeProgram::class);

    try {
        $program->inspect($node, $recipe, $program->tools($node, $recipe));
        $this->fail('Docker CE must refuse recipe convergence.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('node.recipe_docker_provider')
            ->and($exception->getMessage())->toContain('docker-provider:docker-ce', 'node:migrate-docker '.$node->id.' --dry-run');
    }
    expect($agents->calls)->toHaveCount(1)
        ->and($node->refresh()->settings['machine_recipe'] ?? null)->toBeNull();
});

it('sends the native Docker plan its recipe pins and stored intent', function (): void {
    $node = FleetFixtures::node('docker-plan', [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('docker.plan-migration', function (array $input, Node $target) use ($node): array {
        $recipe = new RecipeLoader()->load();
        expect($target->id)->toBe($node->id)
            ->and($input['docker_version'])->toBe($recipe->tools['docker.io']->version)
            ->and($input['containerd_version'])->toBe($recipe->tools['containerd']->version)
            ->and($input)->not->toHaveKey('mode')
            ->and($input['fallback'])->toBeTrue()
            ->and($input['process_ids'])->toBe([]);

        return ['containers' => ['mailpit']];
    });
    app()->instance(AgentCommandRunner::class, $agents);

    expect(Artisan::call('node:migrate-docker', ['node' => $node->name, '--dry-run' => true, '--fallback' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('mailpit')
        ->and($agents->calls)->toHaveCount(1);
});

it('reports final states after reconciling stored specs for migration and policy recovery', function (bool $failConverge, bool $restorePolicies): void {
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
    $agents = new FakeAgentCommandRunner;
    $agents->answer($restorePolicies ? 'docker.restore-migration-policies' : 'docker.prepare-migration', function (array $input) use ($process): array {
        expect($input['process_ids'])->toBe([(string) $process->id])
            ->and($input['process_states']->{(string) $process->id})->toBe('running')
            ->and(json_encode($input))->not->toContain('private');

        return ['fallback' => true, 'process_ids' => [(string) $process->id]];
    });
    $agents->answer('docker.report-migration', function () use ($runtime, $process): array {
        expect($runtime->convergedProcessIds)->toBe([$process->id]);

        return ['healthy' => true, 'containers' => [['name' => 'mysql', 'state' => 'running']]];
    });
    app()->instance(AgentCommandRunner::class, $agents);

    expect(Artisan::call('node:migrate-docker', ['node' => $node->name, '--yes' => true, '--restore-policies' => $restorePolicies]))->toBe($failConverge ? 1 : 0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['healthy'])->toBe(! $failConverge)->and($runtime->convergedProcessIds)->toBe([$process->id]);
    if ($failConverge) {
        expect($report['failed_process_ids'])->toBe([$process->id]);
    }
})->with(['migration' => [false, false], 'migration Process failure' => [true, false], 'policy recovery' => [false, true], 'policy recovery Process failure' => [true, true]]);

it('reports remote failures with recovery advice for the failed action', function (bool $restorePolicies, string $failure, bool $offerFallback): void {
    $node = FleetFixtures::node('docker-failure', [RoleName::AppProd]);
    $agents = new FakeAgentCommandRunner;
    $agents->refuse($restorePolicies ? 'docker.restore-migration-policies' : 'docker.prepare-migration', 'docker.migration_failed', [
        'failure' => $failure, 'containers' => [['name' => 'mailpit', 'state' => 'unreadable']],
    ]);
    app()->instance(AgentCommandRunner::class, $agents);
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);

    expect(Artisan::call('node:migrate-docker', ['node' => $node->name, '--yes' => true, '--restore-policies' => $restorePolicies]))->toBe(1);
    $output = Artisan::output();
    expect($output)->toContain($failure, 'Resume', 'mailpit', 'unreadable')
        ->not->toContain('PASSWORD=private')
        ->and($runtime->convergedProcessIds)->toBe([]);
    $message = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['message'];
    if ($offerFallback) {
        expect($message)->toContain('--fallback');
    } else {
        expect($message)->not->toContain('--fallback');
    }
})->with([
    'package failure' => [false, 'docker-migration:swapping', false],
    'explicit fallback required' => [false, 'docker-migration:fallback-required', true],
    'policy recovery failure' => [true, 'docker-migration:restore-engine', false],
]);

it('does not reconcile Processes after a native migration refusal', function (): void {
    $node = FleetFixtures::node('docker-refusal', [RoleName::AppProd]);
    $runtime = new ProcessesApiFakeRuntimeManager;
    app()->instance(ProcessRuntimeManager::class, $runtime);
    $agents = new FakeAgentCommandRunner;
    $agents->refuse('docker.prepare-migration', 'docker.migration_failed', ['failure' => 'docker-migration:insufficient-space']);
    app()->instance(AgentCommandRunner::class, $agents);

    expect(Artisan::call('node:migrate-docker', ['node' => $node->name, '--yes' => true]))->toBe(1)
        ->and($runtime->convergedProcessIds)->toBe([])
        ->and($agents->calls)->toHaveCount(1);
});

it('keeps Docker migration execution behind the native command runner', function (): void {
    $source = file_get_contents(app_path('Actions/Fleet/MigrateDockerAction.php'));

    expect($source)->toBeString()->not->toContain('RemoteProgram', 'SshExecutor', 'NodeShell', 'Process::run', 'shell_exec', 'Symfony\\Component\\Process')
        ->and(file_exists(resource_path('remote/docker/migrate.py')))->toBeFalse();
});
