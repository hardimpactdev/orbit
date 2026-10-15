<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Support\Facades\Artisan;

it('rejects a package argument that is not a string', function (mixed $package): void {
    Artisan::call('task-vms:package-cache-usage', ['package' => $package, 'version' => '1.0.0']);
})->with([
    'array' => [['acme/demo']],
    'integer' => [42],
    'boolean' => [true],
])->throws(InvalidArgumentException::class, 'The package argument must be a string.');

it('reports a release argument that is not a string', function (): void {
    $this->artisan('gateway:release:prepare', ['commit' => ['main']])
        ->expectsOutputToContain('The commit argument must be a string.')
        ->assertFailed();
});

it('rejects a resume option that is not a string', function (): void {
    Node::query()->create([
        'name' => 'build-host',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.7',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.7',
        'settings' => ['task_vms' => ['cidr' => '10.252.0.0/24', 'max_vms' => 4]],
    ]);

    Artisan::call('task-vms:build-image', ['node' => 'build-host', '--resume' => ['12']]);
})->throws(InvalidArgumentException::class, 'The --resume option must be a string.');
