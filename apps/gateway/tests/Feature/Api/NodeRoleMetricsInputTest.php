<?php

declare(strict_types=1);

use App\Domain\Instances\ProductionPhpRuntimeIdentity;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Nodes\NodeRoleMetricsInput;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'role-metrics-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('c', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    $this->fleet = [];
    foreach ([1 => ['vpn'], 9 => ['metrics'], 13 => ['app-dev'], 18 => []] as $id => $roles) {
        $node = Node::query()->forceCreate(['id' => $id, 'name' => 'role-metrics-'.$id, 'status' => 'active',
            'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.'.$id,
            'wireguard_ip' => '10.44.0.'.$id, 'user' => 'orbit', 'ssh_host_fingerprint' => 'fixture']);
        foreach ($roles as $role) {
            $node->roles()->create(['role' => $role, 'status' => 'active']);
        }
        $this->fleet[$id] = $node;
    }
    app(ExporterPreferenceRepository::class)->put(18, ExporterPreference::Enabled);
    config(['swamp_workers.nodes' => [9, 13]]);
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('captures a role transition against the whole desired fleet without effects or credentials', function (): void {
    $changed = $this->fleet[13]->roles()->create(['role' => 'ci-runner', 'status' => 'provisioning']);
    $plan = app(NodeRoleMetricsInput::class)->capture($changed);
    expect(NodeRoleMetricsInput::participants($plan))->toBe([1, 9, 13, 18]);
    $members = array_column($plan['fleet'], null, 'node_id');
    expect($members[1]['worker'])->toBeFalse()->and($members[18]['worker'])->toBeFalse()
        ->and($members[13]['selected'])->toBeTrue()->and($plan['metrics_node_id'])->toBe(9)
        ->and($plan['runtime_files'])->not->toHaveKey('/etc/orbit/metrics/grafana/admin-password')
        ->and(Operation::query()->count())->toBe(0);
});

it('captures no fleet for a role outside Metrics, so its root skips the Metrics phases', function (): void {
    $changed = $this->fleet[18]->roles()->create(['role' => 'swamp', 'status' => 'provisioning']);
    expect(app(NodeRoleMetricsInput::class)->capture($changed))->toBeNull();
});

it('disables the complete fleet when removing the Metrics owner', function (): void {
    $changed = $this->fleet[9]->roles()->where('role', 'metrics')->firstOrFail();
    $changed->update(['status' => 'removing']);
    $plan = app(NodeRoleMetricsInput::class)->capture($changed);
    expect(NodeRoleMetricsInput::participants($plan))->toBe([1, 9, 13, 18])
        ->and(array_column($plan['fleet'], 'selected'))->each->toBeFalse()
        ->and(array_column($plan['fleet'], 'fpm'))->each->toBeFalse();
});

it('renders membership after the admitted role removal without removing the Node', function (): void {
    $changed = $this->fleet[13]->roles()->where('role', 'app-dev')->firstOrFail();
    $changed->update(['status' => 'removing']);
    $plan = app(NodeRoleMetricsInput::class)->capture($changed);
    $members = array_column($plan['fleet'], null, 'node_id');
    expect($members[13]['selected'])->toBeFalse()->and($members[1]['selected'])->toBeTrue()
        ->and($members[18]['selected'])->toBeTrue()->and(count($members))->toBe(4);
});

it('uses the relocated Metrics owner without dropping the former Node from desired inventory', function (): void {
    $changed = $this->fleet[9]->roles()->where('role', 'metrics')->firstOrFail();
    $changed->update(['node_id' => 13, 'status' => 'provisioning']);
    $plan = app(NodeRoleMetricsInput::class)->capture($changed);
    expect($plan['metrics_node_id'])->toBe(13)->and(NodeRoleMetricsInput::participants($plan))->toBe([1, 9, 13, 18])
        ->and($plan['metrics_address'])->toBe('10.44.0.13');
});

it('has no Metrics work when no serving Metrics assignment exists', function (): void {
    $this->fleet[9]->roles()->delete();
    $changed = $this->fleet[13]->roles()->where('role', 'app-dev')->firstOrFail();
    expect(app(NodeRoleMetricsInput::class)->capture($changed))->toBeNull()
        ->and(NodeRoleMetricsInput::participants(null))->toBe([]);
});

it('keeps recorded FPM pools available for monitoring withdrawal after app-prod removal', function (): void {
    $node = $this->fleet[13];
    $node->roles()->where('role', 'app-dev')->delete();
    $role = $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'metrics-pool', 'slug' => 'metrics-pool',
        'repository_url' => 'https://example.test/metrics-pool.git', 'default_branch' => 'main', 'root' => 'public']);
    $user = 'orbit-app-'.$project->id;
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'default', 'environment' => 'production', 'status' => 'active',
        'checkout_path' => '/home/'.$user.'/releases/initial', 'production_user' => $user, 'production_home' => '/home/'.$user,
        'root' => 'public', 'selected_php_version' => '8.5']);
    $instance->update(ProductionPhpRuntimeIdentity::forProvisioning($instance, '8.5')->attributes());
    $role->update(['status' => 'removing']);
    $plan = app(NodeRoleMetricsInput::class)->capture($role);
    $member = array_column($plan['fleet'], null, 'node_id')[13];
    expect($member['fpm'])->toBeFalse()->and(array_column($member['pools'], 'instance_id'))->toBe([$instance->id])
        ->and($member['pools'][0]['disabled']['configuration']['pool'])->not->toContain('pm.status_path = /orbit-fpm-status');
});
