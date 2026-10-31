<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Fleet\Footprint\PhpRuntimeFootprintArtifact;
use App\Models\Instance;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

it('captures the shared module for every app runtime without Node execution', function (array $roles, string $memory, array $versions): void {
    $node = FleetFixtures::node('app-node', $roles);
    $agents = (new FakeAgentCommandRunner)->answer('php.configure-runtime', ['changed' => true, 'reloaded' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $artifact = app(PhpRuntimeFootprintArtifact::class);
    expect($artifact->applies($node))->toBeTrue();
    $inputs = $artifact->inputs($node);
    expect(array_column($inputs, 'version'))->toBe($versions);
    foreach ($inputs as $input) {
        $settings = parse_ini_string($input['runtime_ini'], scanner_mode: INI_SCANNER_RAW);
        expect($settings)->toHaveCount(8)->toMatchArray([
            'post_max_size' => '64M',
            'upload_max_filesize' => '64M',
            'opcache.memory_consumption' => $memory,
        ]);
    }
    expect($agents->calls)->toBe([]);
})->with([
    'development' => [[RoleName::AppDev], '512', ['8.4', '8.5']],
    'production' => [[RoleName::AppProd], '256', ['8.5']],
    'both roles' => [[RoleName::AppDev, RoleName::AppProd], '512', ['8.4', '8.5']],
]);

it('limits the runtime footprint to active Linux app roles', function (): void {
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
    expect(array_column($artifact->inputs($node), 'version'))->toBe(['8.4', '8.5']);
    expect($agents->calls)->toBe([]);
});
