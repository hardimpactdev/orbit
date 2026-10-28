<?php

declare(strict_types=1);

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Fleet\Footprint\PhpRuntimeFootprintArtifact;
use App\Models\Instance;
use App\Models\NodeFootprint as NodeFootprintRecord;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

it('publishes the shared module on app Nodes and skips a matching footprint', function (array $roles, string $memory, array $versions): void {
    $node = FleetFixtures::node('app-node', $roles);
    $agents = (new FakeAgentCommandRunner)->answer('php.configure-runtime', ['changed' => true, 'reloaded' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $artifact = app(PhpRuntimeFootprintArtifact::class);
    $footprint = new NodeFootprint(app(AgentDelivery::class), [$artifact]);

    expect($artifact->applies($node))->toBeTrue();
    expect($footprint->converge($node)->artifacts)->toBe(['php-runtime' => 'applied']);
    expect(array_unique(array_column($agents->calls, 'command')))->toBe(['php.configure-runtime']);
    expect(array_column(array_column($agents->calls, 'input'), 'version'))->toBe($versions);
    foreach ($agents->calls as $call) {
        $settings = parse_ini_string($call['input']['runtime_ini'], scanner_mode: INI_SCANNER_RAW);
        expect($settings)->toHaveCount(8)->toMatchArray([
            'post_max_size' => '64M',
            'upload_max_filesize' => '64M',
            'opcache.memory_consumption' => $memory,
        ]);
    }
    $agents->calls = [];
    expect($footprint->converge($node)->artifacts)->toBe(['php-runtime' => 'unchanged']);
    expect($agents->calls)->toBe([]);

    NodeFootprintRecord::query()->where('node_id', $node->id)->update(['artifacts' => ['php-runtime' => 'previous-renderer']]);
    expect($footprint->converge($node)->artifacts)->toBe(['php-runtime' => 'applied']);
    expect($agents->calls)->toHaveCount(count($versions));
})->with([
    'development' => [[RoleName::AppDev], '512', ['8.4', '8.5']],
    'production' => [[RoleName::AppProd], '256', ['8.5']],
    'both roles' => [[RoleName::AppDev, RoleName::AppProd], '512', ['8.4', '8.5']],
]);

it('limits the runtime footprint to active Linux app roles and reports a no-op publication', function (): void {
    $agents = (new FakeAgentCommandRunner)->answer('php.configure-runtime', ['changed' => false, 'reloaded' => false]);
    app()->instance(AgentCommandRunner::class, $agents);
    $artifact = app(PhpRuntimeFootprintArtifact::class);
    $dev = FleetFixtures::node('dev', [RoleName::AppDev]);
    $prod = FleetFixtures::node('prod', [RoleName::AppProd]);
    $inactive = FleetFixtures::node('inactive', [RoleName::AppDev]);
    $inactive->roles()->update(['status' => LifecycleStatus::Failed]);
    $inactive->load('roles');

    expect($artifact->applies($inactive))->toBeFalse();
    expect($artifact->applies(FleetFixtures::node('database', [RoleName::Database])))->toBeFalse();
    expect($artifact->applies(FleetFixtures::node('mac', [RoleName::AppDev], platform: 'macos')))->toBeFalse();
    expect($artifact->digest($dev))->not->toBe($artifact->digest($prod));
    expect($artifact->apply($dev))->toBeFalse();
});

it('includes an active production Instance version outside its recipe profile in the footprint', function (): void {
    $node = FleetFixtures::node('prod-selected', [RoleName::AppProd]);
    $agents = (new FakeAgentCommandRunner)->answer('php.configure-runtime', ['changed' => true, 'reloaded' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $artifact = app(PhpRuntimeFootprintArtifact::class);
    $before = $artifact->digest($node);
    $project = Project::query()->create(['name' => 'Selected PHP', 'slug' => 'selected-php', 'repository_url' => 'https://example.test/php.git', 'default_branch' => 'main']);
    Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production',
        'checkout_path' => '/home/orbit-selected', 'status' => InstanceState::Active, 'selected_php_version' => '8.4',
    ]);

    expect($artifact->digest($node))->not->toBe($before);
    expect($artifact->apply($node))->toBeTrue();
    expect(array_column(array_column($agents->calls, 'input'), 'version'))->toBe(['8.4', '8.5']);
});
