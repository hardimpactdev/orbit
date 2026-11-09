<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskContainerHostIntent;
use App\Domain\TaskVms\TaskVmContainers;
use App\Domain\TaskVms\TaskVmHost;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function containerIntentHost(?TaskVmContainers $containers, string $architecture = 'x86_64'): array
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => $architecture, 'public_ssh_host' => '10.44.0.9',
        'wireguard_ip' => '10.44.0.9', 'user' => 'orbit',
    ]));

    return [$node, new TaskVmHost($node->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 2, 4, '8GiB', '40GiB', ciPlaces: 2, containers: $containers)];
}

it('gives a host without containers no container side', function (): void {
    [$node, $host] = containerIntentHost(null);

    expect(app(TaskContainerHostIntent::class)->for($node, $host))->toBeNull();
});

it('names the container project, bridge address, alias and places, and the recipe\'s Incus line and packages for the host', function (): void {
    [$node, $host] = containerIntentHost(new TaskVmContainers(1, 2, 'orbit-task-containers', 'orbittaskc0', '10.251.78.0/24'));
    $recipe = app(RecipeImageBuilds::class)->currentRecipe();

    $intent = app(TaskContainerHostIntent::class)->for($node, $host);

    expect(array_diff_key($intent, ['incus' => true]))->toBe([
        'project' => 'orbit-task-containers', 'network' => 'orbittaskc0', 'cidr' => '10.251.78.0/24', 'address' => '10.251.78.1/24',
        'alias' => 'ubuntu-26.04-ct', 'places' => 3,
    ])
        ->and($intent['incus']['line'])->toBe(implode('.', array_slice(explode('.', $recipe->tools['incus']->version), 0, 2)))
        ->and($intent['incus']['line'])->toMatch('/^7\.0$/')
        ->and(array_column($intent['incus']['packages'], 'name'))->toBe(['incus', 'incus-base', 'incus-client']);
    foreach ($intent['incus']['packages'] as $package) {
        $artifact = $recipe->tools[$package['name']]->artifacts['amd64'];
        expect($package['sha256'])->toBe($artifact->digest)
            // The mirror's content-addressed copy first, then upstream: the same order a base converge fetches in.
            ->and($package['urls'])->toBe([$recipe->artifactMirror.'/'.$package['name'].'_'.$artifact->digest.'.deb', $artifact->location]);
    }
});

it('refuses a container host whose architecture the recipe does not build', function (): void {
    [$node, $host] = containerIntentHost(new TaskVmContainers(1, 1, 'orbit-task-containers', 'orbittaskc0', '10.251.78.0/24'), 'riscv64');

    expect(fn () => app(TaskContainerHostIntent::class)->for($node, $host))->toThrow(ResourceOperationException::class);
});
