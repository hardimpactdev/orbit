<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\TaskVmSettingsBuilder;

require_once __DIR__.'/../../Support/FakeIncusImageHost.php';

function recipeImageHost(): Node
{
    return Node::query()->create(['name' => 'recipe-beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit']);
}

function recipeImageTransport(Node $host): FakeIncusImageHost
{
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $incus = new FakeIncusImageHost;
    $incus->bindAgentInventory();
    $incus->recipeImages = true;
    $incus->images = [IMAGE_STOCK => [TaskVmHost::SourceImage], IMAGE_OLD => [TaskVmHost::BaseImage]];
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return IMAGE_KEY;
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    $directory = sys_get_temp_dir().'/recipe-image-artifacts';
    @mkdir($directory.'/sandbox-artifacts', 0700, true);
    file_put_contents($directory.'/sandbox-artifacts/pi-test', 'pinned pi bytes');
    config(['orbit.home' => $directory]);
    $signer = Mockery::mock(LeafCertificateSigner::class);
    $signer->shouldReceive('rootCertificate')->andReturn('public Orbit root certificate');
    app()->instance(LeafCertificateSigner::class, $signer);

    return $incus;
}

function recipeImageDocument(string $snapshot = '20261014T000000Z'): string
{
    $data = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
    $data['ubuntu']['snapshot'] = $snapshot;
    $data['tools']['pi']['artifacts']['amd64']['filename'] = 'pi-test';
    $data['tools']['pi']['artifacts']['amd64']['sha256'] = hash('sha256', 'pinned pi bytes');

    return json_encode($data, JSON_THROW_ON_ERROR);
}

function recipeImageCurrent(string $document): void
{
    $directory = sys_get_temp_dir().'/recipe-image-release';
    @mkdir($directory.'/releases/current/apps/gateway/resources/machine-software', 0700, true);
    if (! is_link($directory.'/orbit')) {
        symlink('releases/current', $directory.'/orbit');
    }
    file_put_contents($directory.'/orbit/apps/gateway/resources/machine-software/recipe.json', $document);
    config(['orbit.gateway_checkout' => $directory.'/orbit/apps/gateway']);
}

it('keeps aliases for base and every role within the Incus name limit', function (string $prefix): void {
    $identity = 'sha256:0bd780fc6472'.str_repeat('a', 52);
    $aliases = [];
    foreach (['base', 'gateway', 'app-dev', 'app-prod', 'operator'] as $profile) {
        $alias = RecipeImageBuilds::alias($prefix, $identity, $profile, 42);
        expect(strlen($alias))->toBeLessThanOrEqual(64);
        expect($alias)->toEndWith('-recipe-0bd780fc6472-42-'.$profile);
        $aliases[] = $alias;
    }
    expect(array_unique($aliases))->toHaveCount(5);
})->with([TaskVmHost::BaseImage, 'shark-task-base', str_repeat('a', 254)]);

it('records one immutable Swamp image Operation and reuses unchanged intent', function (): void {
    $host = recipeImageHost();
    recipeImageTransport($host);
    recipeImageCurrent(recipeImageDocument());
    $builds = app(RecipeImageBuilds::class);
    $first = $builds->reconcile()[0];
    $again = $builds->reconcile()[0];
    expect($again->id)->toBe($first->id);
    $operation = Operation::query()->where('kind', 'task_vm.image_build')->sole();
    expect($operation->target_id)->toBe($first->id)->and($operation->node_id)->toBe($host->id);
    expect($operation->input['recipe'])->toBeString();
    expect($operation->input['generation'])->toBe($first->recipe_generation);
});
