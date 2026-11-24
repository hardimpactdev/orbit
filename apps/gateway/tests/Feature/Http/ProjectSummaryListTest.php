<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = SwampNodeFixture::ensure();
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

    $this->caller = summaryNode('macbook', '10.44.1.20', 'macos');
    $this->mini = summaryNode('mini', '10.44.1.10');
    $this->vps = summaryNode('vps', '10.44.1.11');
    $this->caller->accessibleNodes()->attach($this->mini->id);
});

function summaryNode(string $name, string $ip, string $platform = 'linux'): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'platform' => $platform,
        'wireguard_ip' => $ip,
        'public_ssh_host' => $ip,
        'user' => $platform === 'macos' ? 'nick' : 'orbit',
    ]));
}

function summaryProject(string $slug, string $repository): Project
{
    return Project::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'repository_url' => $repository, 'default_branch' => 'main']);
}

function summaryInstance(Project $project, Node $node, string $name): Instance
{
    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => 'development',
        'branch' => $name === 'default' ? 'main' : $name,
        'checkout_path' => "/srv/apps/{$project->slug}/{$name}",
        'status' => InstanceState::Active,
    ]);
}

it('lets a Node with access to another Node read the Project summary fields', function (): void {
    $orbit = summaryProject('orbit', 'https://github.com/HardImpactDev/orbit.git');
    summaryInstance($orbit, $this->mini, 'default');
    summaryInstance($orbit, $this->mini, 'feat-a');
    summaryInstance($orbit, $this->mini, 'feat-b');

    $data = $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/projects')
        ->assertOk()
        ->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0])->toMatchArray([
            'id' => $orbit->id,
            'slug' => 'orbit',
            'name' => 'Orbit',
            'repository' => 'github.com/HardImpactDev/orbit',
            'node_name' => 'mini',
            'icon' => null,
            'color' => null,
            'instance_count' => 3,
            // The existing Project fields stay.
            'repository_url' => 'https://github.com/HardImpactDev/orbit.git',
            'default_branch' => 'main',
        ])
        ->and($data[0])->toHaveKeys(['code', 'type', 'source_access', 'container_server']);
});

it('counts only the Instances on Nodes the caller can reach and names no Node for a Project without a default', function (): void {
    $shop = summaryProject('shop', 'https://github.com/acme/shop.git');
    summaryInstance($shop, $this->vps, 'default');
    summaryInstance($shop, $this->mini, 'feat-a');
    $site = summaryProject('site', 'https://github.com/acme/site.git');
    summaryInstance($site, $this->mini, 'feat-b');
    summaryProject('hidden', 'https://github.com/acme/hidden.git');

    $data = collect($this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip])
        ->getJson('/api/v1/projects')->assertOk()->json('data'))->keyBy('slug');

    expect($data->keys()->all())->toBe(['shop', 'site'])
        ->and($data['shop']['instance_count'])->toBe(1)
        ->and($data['shop']['node_name'])->toBe('vps')
        ->and($data['site']['instance_count'])->toBe(1)
        ->and($data['site']['node_name'])->toBeNull();
});

it('counts every Instance for the Gateway and reads the summary in a fixed number of queries', function (): void {
    foreach (['alpha', 'beta', 'gamma'] as $slug) {
        $project = summaryProject($slug, "https://github.com/acme/{$slug}.git");
        summaryInstance($project, $this->vps, 'default');
        summaryInstance($project, $this->mini, 'feat-a');
    }
    summaryProject('empty', 'https://github.com/acme/empty.git');

    DB::enableQueryLog();
    $data = collect($this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip])
        ->getJson('/api/v1/projects')->assertOk()->json('data'))->keyBy('slug');
    $projectQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"projects"')
        || str_contains($query['query'], 'from "instances"'));

    expect($data->map(fn (array $project): array => [$project['instance_count'], $project['node_name']])->all())->toBe([
        'alpha' => [2, 'vps'], 'beta' => [2, 'vps'], 'empty' => [0, null], 'gamma' => [2, 'vps'],
    ])->and($projectQueries)->toHaveCount(2);
});
