<?php

declare(strict_types=1);

use App\Models\Node;

it('reports unavailable Gateway bootstrap without creating a Node', function (): void {
    $before = Node::query()->count();

    $this->artisan('orbit:bootstrap', ['public-host' => 'gateway.example.test', '--wireguard-ip' => '10.44.0.2'])
        ->expectsOutput('Gateway bootstrap failed at step [bootstrap] with error [gateway.bootstrap_unavailable].')
        ->assertExitCode(1);

    expect(Node::query()->count())->toBe($before);
});
