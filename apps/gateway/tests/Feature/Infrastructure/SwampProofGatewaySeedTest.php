<?php

declare(strict_types=1);

use App\Models\Cluster;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Validation\ValidationException;

require_once dirname(__DIR__, 5).'/tools/swamp-proof-seed.php';

/** @return array<string, mixed> */
function proof_gateway_seed_facts(): array
{
    $nodes = [];
    foreach (['gateway' => ['gateway', 'vpn'], 'app-dev' => ['app-dev'], 'operator' => []] as $name => $roles) {
        $index = count($nodes) + 1;
        $nodes[] = ['key' => $name, 'name' => 'proof-'.$name, 'platform' => 'linux', 'architecture' => 'x86_64',
            'user' => 'orbit', 'home' => '/home/orbit', 'public_address' => '192.0.2.'.$index,
            'wireguard_ip' => '10.44.0.'.$index, 'wireguard_public_key' => base64_encode(str_repeat("\x01", 32)),
            'ssh_host_key_type' => 'ssh-ed25519',
            'ssh_host_key' => base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat("\x01", 32)),
            'roles' => $roles, 'tld' => null];
    }

    return ['cluster' => ['name' => 'proof-test'], 'nodes' => $nodes,
        'project' => ['name' => 'Swamp proof test', 'slug' => 'swamp-proof-test', 'type' => 'node-package',
            'repository_url' => 'https://github.com/hardimpactdev/swamp-proof-fixture.git',
            'default_branch' => 'main', 'root' => '.', 'source_access' => 'github_app']];
}

it('seeds only fixture records with assigned ids and observed public pins', function (): void {
    $facts = proof_gateway_seed_facts();

    $result = new SwampProofFixtureSeeder()->execute($facts);

    expect($result)->toHaveKeys(['seeded', 'cluster_id', 'project_id', 'nodes'])->not->toHaveKey('ready');
    expect($result['seeded'])->toBeTrue();
    expect(Node::query()->count())->toBe(3);
    expect(Cluster::query()->count())->toBe(1);
    expect(Project::query()->count())->toBe(1);
    $gateway = Node::query()->findOrFail($result['nodes']['gateway']);
    expect($gateway->public_ssh_host)->toBe($facts['nodes'][0]['public_address'])
        ->and($gateway->ssh_host_key)->toBe($facts['nodes'][0]['ssh_host_key'])
        ->and($gateway->wireguard_public_key)->toBe($facts['nodes'][0]['wireguard_public_key']);
    $operator = Node::query()->findOrFail($result['nodes']['operator']);
    expect($operator->accessibleNodes->modelKeys())->toBe([$gateway->id]);
    $project = Project::query()->findOrFail($result['project_id']);
    expect($project->task_workspace_routed)->toBeFalse()->and($project->review_and_merge)->toBeFalse();
});

it('refuses an existing database without changing its records', function (string $existing): void {
    $facts = proof_gateway_seed_facts();
    match ($existing) {
        'node' => Node::query()->create(['name' => 'existing', 'status' => 'active', 'public_ssh_host' => '192.0.2.99', 'user' => 'orbit', 'platform' => 'linux']),
        'cluster' => Cluster::query()->create(['name' => 'existing']),
        'project' => Project::query()->create($facts['project']),
    };
    $before = [Node::query()->count(), Cluster::query()->count(), Project::query()->count()];

    expect(fn () => new SwampProofFixtureSeeder()->execute($facts))->toThrow(RuntimeException::class, 'proof.seed_database_not_empty');

    expect([Node::query()->count(), Cluster::query()->count(), Project::query()->count()])->toBe($before);
})->with(['node', 'cluster', 'project']);

it('refuses supplied ids before writing fixture records', function (): void {
    $facts = proof_gateway_seed_facts();
    $facts['nodes'][0]['id'] = 209;

    expect(fn () => new SwampProofFixtureSeeder()->execute($facts))->toThrow(ValidationException::class);

    expect(Node::query()->count())->toBe(0);
    expect(Cluster::query()->count())->toBe(0);
    expect(Project::query()->count())->toBe(0);
});

it('refuses an invalid observed SSH packet before writing fixture records', function (): void {
    $facts = proof_gateway_seed_facts();
    $facts['nodes'][1]['ssh_host_key'] = base64_encode('not an Ed25519 packet');

    expect(fn () => new SwampProofFixtureSeeder()->execute($facts))->toThrow(RuntimeException::class, 'proof.seed_invalid_ssh_host_key');

    expect(Node::query()->count())->toBe(0);
    expect(Cluster::query()->count())->toBe(0);
    expect(Project::query()->count())->toBe(0);
});

it('refuses a projectless top-level Task hidden by its normal global scope', function (): void {
    Task::withoutGlobalScopes()->create(['title' => 'Existing root', 'brief' => 'Retain ownership', 'status' => 'todo', 'parent_id' => null]);

    expect(fn () => new SwampProofFixtureSeeder()->execute(proof_gateway_seed_facts()))
        ->toThrow(RuntimeException::class, 'proof.seed_database_not_empty');

    expect(Task::withoutGlobalScopes()->count())->toBe(1);
    expect(Node::query()->count())->toBe(0);
    expect(Cluster::query()->count())->toBe(0);
    expect(Project::query()->count())->toBe(0);
});
