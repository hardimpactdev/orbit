<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Actions\Instances\SelectInstanceSeedAction;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DeploymentResult;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Models\Instance;
use Tests\Support\DevelopmentDeploymentFixture;

beforeEach(function (): void {
    $this->fixture = new DevelopmentDeploymentFixture;
    app()->instance(DevelopmentDeployment::class, $this->fixture->deployment);
});

/** @param list<DevelopmentDeployStep> $steps */
function plain_steps(Instance $instance, array $steps): void
{
    foreach ($steps as $step) {
        app(ProjectDevelopmentDeployStepStore::class)->create($instance->project, $step, null, null);
    }
}

function plain_deploy(Instance $instance, ?string $commit = null, ?DeploymentRequest $request = null): DeploymentResult
{
    $action = app(DeployDefaultInstanceAction::class);

    return $action->execute($instance, operation_steps($instance, $action), $request ?? DeploymentRequest::withoutOutput(), $commit);
}

it('deploys the selected branch commit and publishes a seed only after every required step passes', function (): void {
    $instance = $this->fixture->instance;
    plain_steps($instance, [new DevelopmentDeployStep('install', 'project-install')]);

    $result = plain_deploy($instance);

    expect($result->succeeded)->toBeTrue()
        ->and($result->commit)->toBe(str_repeat('b', 40))
        ->and($instance->fresh()->seed_path)->toBe($this->fixture->home)
        ->and($instance->fresh()->seed_commit)->toBe(str_repeat('b', 40))
        ->and($this->fixture->agents->commands())->toBe(['instances.resolve-development-target', 'instances.checkout-development-default', 'instances.run-development-step'])
        ->and($this->fixture->agents->calls[0]['input'])->toMatchArray(['home' => $this->fixture->home, 'branch' => 'main', 'commit' => null])
        ->and($this->fixture->agents->calls[1]['input']['commit'])->toBe(str_repeat('b', 40))
        ->and($this->fixture->agents->calls[2]['input'])->toMatchArray(['home' => $this->fixture->home, 'script' => 'project-install', 'step' => 'install']);

    $consumer = Instance::query()->create([
        'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => 'feature',
        'checkout_path' => '/srv/apps/dev935/feature', 'status' => 'reserved',
    ]);
    app(SelectInstanceSeedAction::class)->execute($consumer);
    expect($consumer->fresh()->seed_path)->toBe($this->fixture->home)
        ->and($consumer->fresh()->seed_commit)->toBe(str_repeat('b', 40))
        ->and($consumer->fresh()->seed_repository)->toBe($this->fixture->home);
});

it('keeps the previous seed after a failed step and retries the ordered steps on the next deployment', function (): void {
    $instance = $this->fixture->instance;
    $instance->update(['seed_path' => $this->fixture->home, 'seed_commit' => str_repeat('a', 40), 'seed_repository' => $this->fixture->home]);
    plain_steps($instance, [new DevelopmentDeployStep('install', 'project-install'), new DevelopmentDeployStep('verify', 'project-verify')]);
    $agents = $this->fixture->agents;
    $agents->answer('instances.run-development-step', ['exit_code' => 1, 'output' => [['stream' => 'stderr', 'value' => 'Install failed']], 'truncated' => false]);

    $failed = plain_deploy($instance);

    expect($failed->failure?->errorCode)->toBe('deployment.step_failed')
        ->and($failed->commit)->toBe(str_repeat('b', 40))
        ->and($instance->fresh()->seed_commit)->toBe(str_repeat('a', 40))
        ->and($agents->commands())->toBe(['instances.resolve-development-target', 'instances.checkout-development-default', 'instances.run-development-step']);

    $agents->calls = [];
    $agents->answer('instances.run-development-step', ['exit_code' => 0, 'output' => [], 'truncated' => false]);
    $retried = plain_deploy($instance);
    expect($retried->succeeded)->toBeTrue()
        ->and($instance->fresh()->seed_commit)->toBe(str_repeat('b', 40))
        ->and(array_column(array_slice(array_column($agents->calls, 'input'), 2), 'step'))->toBe(['install', 'verify']);
});

it('passes a pinned commit to the agent and maps a commit outside the branch without checking out', function (): void {
    $instance = $this->fixture->instance;
    $agents = $this->fixture->agents;
    $commit = str_repeat('b', 40);

    expect(plain_deploy($instance, $commit)->succeeded)->toBeTrue()
        ->and($agents->calls[0]['input']['commit'])->toBe($commit)
        ->and($instance->fresh()->seed_commit)->toBe($commit);

    $agents->calls = [];
    $agents->refuse('instances.resolve-development-target', 'instances.commit_not_on_branch');
    $refused = plain_deploy($instance, str_repeat('f', 40));
    expect($refused->failure?->errorCode)->toBe('deployment.commit_not_on_branch')
        ->and($refused->commit)->toBe(str_repeat('f', 40))
        ->and($agents->commands())->toBe(['instances.resolve-development-target'])
        ->and($instance->fresh()->seed_commit)->toBe($commit);
});

it('maps checkout refusals without running steps or publishing a seed', function (string $code, string $expected): void {
    $instance = $this->fixture->instance;
    plain_steps($instance, [new DevelopmentDeployStep('install', 'project-install')]);
    $this->fixture->agents->refuse('instances.checkout-development-default', $code, ['changes' => 'public/index.php']);
    $events = [];

    $result = plain_deploy($instance, request: new DeploymentRequest(static function ($event) use (&$events): void {
        $events[] = $event->value;
    }));

    expect($result->failure?->errorCode)->toBe($expected)
        ->and($instance->fresh()->seed_commit)->toBeNull()
        ->and($this->fixture->agents->commands())->toBe(['instances.resolve-development-target', 'instances.checkout-development-default']);
    if ($code === 'instances.checkout_dirty') {
        expect(implode('', $events))->toContain('public/index.php');
    }
})->with([
    ['instances.checkout_dirty', 'deployment.checkout_dirty'],
    ['instances.branch_diverged', 'deployment.branch_diverged'],
    ['instances.lifecycle_busy', 'instance.lifecycle_busy'],
]);

it('sends the step timeout and records the agent exit status and output', function (): void {
    $instance = $this->fixture->instance;
    plain_steps($instance, [new DevelopmentDeployStep('hang', 'project-wait', timeoutSeconds: 1)]);
    $this->fixture->agents->answer('instances.run-development-step', ['exit_code' => 143, 'output' => [['stream' => 'stdout', 'value' => 'started']], 'truncated' => false, 'timed_out' => true]);

    $result = plain_deploy($instance);

    expect($result->failure?->errorCode)->toBe('deployment.step_failed')
        ->and($result->commands[0]->result->exitCode)->toBe(143)
        ->and($result->commands[0]->result->stdout)->toBe('started')
        ->and($this->fixture->agents->calls[2]['input']['timeout_seconds'])->toBe(1)
        ->and($this->fixture->agents->deadlines[2])->toBe(31.0);
});
