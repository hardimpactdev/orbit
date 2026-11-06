<?php

declare(strict_types=1);

use App\Actions\Instances\ListInstanceReleasesAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => SwampNodeFixture::ensure());

function release_list_instance(RoleName $role, string $name = 'default'): Instance
{
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create([
        'name' => 'apps-'.$role->value, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $node->roles()->create(['role' => $role, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'site', 'slug' => 'site', 'repository_url' => 'https://example.test/site.git', 'default_branch' => 'main', 'root' => 'public']);
    $user = 'orbit-app-'.$project->id;
    $production = $role === RoleName::AppProd;

    return Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'environment' => $production ? 'production' : 'development', 'status' => 'active',
        'checkout_path' => $production ? "/home/{$user}/releases/initial" : '/home/orbit/apps/site/default', 'root' => 'public',
        ...($production ? ['production_user' => $user, 'production_home' => "/home/{$user}"] : []),
        'provisioning_step' => 'active',
    ]);
}

function release_list_deployment(Instance $instance, string $kind, string $status, string $release, ?string $selected = null): void
{
    InstanceDeployment::query()->create([
        'instance_id' => $instance->id, 'kind' => $kind, 'status' => $status, 'release' => $release, 'selected_release' => $selected ?? $release,
        'commit' => str_repeat('a', 40), 'started_at' => now(),
    ]);
}

it('lists a freshly cloned production Instance with its first release selected', function (): void {
    $state = app(ListInstanceReleasesAction::class)->execute(release_list_instance(RoleName::AppProd));

    expect($state->releases)->toBe(['initial'])->and($state->selectedRelease)->toBe('initial');
});

it('lists the three releases the history keeps, sorted by name, with the last activation selected', function (): void {
    $instance = release_list_instance(RoleName::AppProd);
    foreach (['20261101-a', '20261102-b', '20261103-c'] as $release) {
        release_list_deployment($instance, 'deploy', 'succeeded', $release);
    }

    $state = app(ListInstanceReleasesAction::class)->execute($instance);

    expect($state->releases)->toBe(['20261101-a', '20261102-b', '20261103-c'])
        ->and($state->selectedRelease)->toBe('20261103-c');
});

it('moves the selection on a rollback and keeps the list', function (): void {
    $instance = release_list_instance(RoleName::AppProd);
    release_list_deployment($instance, 'deploy', 'succeeded', '20261101-a');
    release_list_deployment($instance, 'deploy', 'succeeded', '20261102-b');
    release_list_deployment($instance, 'rollback', 'succeeded', '20261101-a');

    $state = app(ListInstanceReleasesAction::class)->execute($instance);

    expect($state->releases)->toBe(['20261101-a', '20261102-b', 'initial'])
        ->and($state->selectedRelease)->toBe('20261101-a');
});

it('lists nothing for a development default', function (): void {
    expect(app(ListInstanceReleasesAction::class)->execute(release_list_instance(RoleName::AppDev))->releases)->toBe([]);
});

it('refuses any other development Instance', function (): void {
    app(ListInstanceReleasesAction::class)->execute(release_list_instance(RoleName::AppDev, 'feature'));
})->throws(ResourceOperationException::class, 'The production deployment identity is invalid.');

it('keeps the new selection, the one before it and the newest other release, so a fourth deploy drops the oldest', function (): void {
    $instance = release_list_instance(RoleName::AppProd);
    foreach (['20261101-a', '20261102-b', '20261103-c', '20261104-d'] as $release) {
        release_list_deployment($instance, 'deploy', 'succeeded', $release);
    }

    $state = app(ListInstanceReleasesAction::class)->execute($instance);

    expect($state->releases)->toBe(['20261102-b', '20261103-c', '20261104-d'])
        ->and($state->selectedRelease)->toBe('20261104-d');
});

it('selects a failed deploy only when it activated its release', function (bool $activated): void {
    $instance = release_list_instance(RoleName::AppProd);
    release_list_deployment($instance, 'deploy', 'succeeded', '20261101-a');
    release_list_deployment($instance, 'deploy', 'failed', '20261102-b', $activated ? '20261102-b' : '20261101-a');

    $state = app(ListInstanceReleasesAction::class)->execute($instance);

    expect($state->releases)->toContain('20261102-b')
        ->and($state->selectedRelease)->toBe($activated ? '20261102-b' : '20261101-a');
})->with([true, false]);

it('refuses a production Instance that is not active', function (): void {
    $instance = release_list_instance(RoleName::AppProd);
    $instance->update(['status' => 'reserved']);

    app(ListInstanceReleasesAction::class)->execute($instance);
})->throws(ResourceOperationException::class, 'The production deployment identity is invalid.');
