<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

it('freezes production intent without copying scripts or environment secrets', function (): void {
    [, , , $instance] = deployment_api_fixture();
    $instance->update(['selected_php_version' => '8.5', ...ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes()]);
    $instance->environmentValues()->create(['env_key' => 'API_TOKEN', 'env_value' => 'environment-secret-sentinel']);
    app(InstanceDeployStepStore::class)->replaceAll($instance, [new DeploymentStep('migrate', DeploymentPhase::BeforeActivation, 'command-secret-sentinel', 60)]);
    $intent = app(InstanceDeploymentIntent::class)->snapshot($instance);
    expect($intent['variant'])->toBe('production')->and($intent['php'])->toBeTrue()
        ->and($intent['branch'])->toBe('main')->and($intent['steps'])->toBe([
            ['name' => 'migrate', 'phase' => 'before_activation', 'required' => true, 'timeout_seconds' => 60, 'command_digest' => hash('sha256', 'command-secret-sentinel')],
        ])->and(json_encode($intent))->not->toContain('command-secret-sentinel', 'environment-secret-sentinel');
});

it('freezes Project development steps and required flags on the default branch', function (): void {
    $instance = dev935_instance();
    $instance->update(['deployment_branch' => 'ignored']);
    dev935_steps($instance, [new DevelopmentDeployStep('warm', 'private-command', 45, false)]);
    $intent = app(InstanceDeploymentIntent::class)->snapshot($instance);
    expect($intent['variant'])->toBe('development')->and($intent['branch'])->toBe('main')
        ->and($intent['home'])->toBe($instance->checkout_path)->and($intent['steps'][0])->toBe([
            'name' => 'warm', 'phase' => 'before_activation', 'required' => false,
            'timeout_seconds' => 45, 'command_digest' => hash('sha256', 'private-command'),
        ])->and(json_encode($intent))->not->toContain('private-command');
});

it('refuses non-default development and unsupported source states', function (array $changes): void {
    $instance = dev935_instance();
    $instance->update($changes);
    expect(fn () => app(InstanceDeploymentIntent::class)->snapshot($instance))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('deployment_config.unavailable'));
})->with([
    'other Instance' => [['name' => 'feature']],
    'linked worktree' => [['source_layout' => 'linked_worktree']],
    'not active' => [['status' => 'source_resolved']],
]);

it('admits no deploy scripts for rollback and refuses development rollback', function (): void {
    [, , , $production] = deployment_api_fixture();
    app(InstanceDeployStepStore::class)->replaceAll($production, [new DeploymentStep('migrate', DeploymentPhase::BeforeActivation, 'never')]);
    $intent = app(InstanceDeploymentIntent::class)->snapshot($production, rollback: true);
    expect($intent['variant'])->toBe('rollback')->and($intent['steps'])->toBe([]);
    $development = dev935_instance();
    expect(fn () => app(InstanceDeploymentIntent::class)->snapshot($development, rollback: true))->toThrow(ResourceOperationException::class);
});

it('keeps release names stable and ordered across admission times and id digit boundaries', function (): void {
    $older = new Operation()->forceFill(['id' => 9, 'created_at' => '2026-10-28 22:12:34']);
    $newer = new Operation()->forceFill(['id' => 10, 'created_at' => '2026-10-28 22:12:34']);
    $nextSecond = new Operation()->forceFill(['id' => 1, 'created_at' => '2026-10-28 22:12:35']);

    $first = InstanceDeploymentIntent::releaseName($older);
    $second = InstanceDeploymentIntent::releaseName($newer);
    $third = InstanceDeploymentIntent::releaseName($nextSecond);
    expect($first)->toBe('20261028221234-op-00000000000000000009');
    expect(strcmp($first, $second))->toBeLessThan(0);
    expect(strcmp($second, $third))->toBeLessThan(0);
    expect(InstanceDeploymentIntent::releaseName($older))->toBe($first);
});

it('accepts activation only from the matching server transport receipt', function (): void {
    $release = ['name' => 'retained', 'path' => '/home/proof/releases/retained', 'commit' => str_repeat('a', 40)];
    $operation = new Operation(['state' => ['steps' => ['activation' => $release]]]);
    expect(InstanceDeploymentIntent::provenActivation($operation))->toBeNull();
    $operation->state = ['executor_runs' => ['activation' => ['name' => 'instance.activate-release', 'transport' => 'gateway', 'result' => $release]]];
    expect(InstanceDeploymentIntent::provenActivation($operation))->toBeNull();
    $operation->state = ['executor_runs' => ['activation' => ['name' => 'instance.activate-release', 'transport' => 'instance-deploy-agent', 'result' => $release]]];
    expect(InstanceDeploymentIntent::provenActivation($operation)?->name)->toBe('retained');
});
