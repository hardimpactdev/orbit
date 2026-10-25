<?php

declare(strict_types=1);

use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\LifecycleAgent;

it('selects the native CI cache import operation only for setup', function (): void {
    $project = Project::query()->create(['name' => 'CI', 'slug' => 'ci', 'repository_url' => 'https://github.com/acme/orbit.git']);
    $node = Node::query()->create(['name' => 'ci', 'wireguard_ip' => '192.0.2.9', 'public_ssh_host' => '192.0.2.9']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'ci', 'checkout_path' => '/srv/apps/ci', 'status' => 'active']);
    $store = new ProjectLifecycleStepStore;
    $store->create($project, LifecyclePhase::Setup, new LifecycleStep('ci-caches', LifecycleStep::ImportCiCaches, 60), null, null);
    $store->create($project, LifecyclePhase::Teardown, new LifecycleStep('ci-caches', LifecycleStep::ImportCiCaches, 60), null, null);
    $transport = new LifecycleAgent;

    expect($transport->runner()->run($instance, LifecyclePhase::Setup))->toBeTrue()
        ->and(fn () => $transport->runner()->run($instance, LifecyclePhase::Teardown))->toThrow(ResourceOperationException::class, 'setup-only')
        ->and($transport->inputs)->toHaveCount(1)
        ->and($transport->inputs[0]['command'])->toBe('import_ci_caches')
        ->and($transport->inputs[0]['checkout'])->toBe('/srv/apps/ci')
        ->and(GatewaySetupSteps::owns(LifecycleStep::ImportCiCaches))->toBeTrue()
        ->and(GatewaySetupSteps::owns('@orbit/tia-baseline'))->toBeFalse();
});
