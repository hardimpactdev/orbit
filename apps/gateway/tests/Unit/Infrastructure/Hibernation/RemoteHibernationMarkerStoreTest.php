<?php

declare(strict_types=1);

use App\Domain\Hibernation\RuntimeHibernation;
use App\Infrastructure\Hibernation\RemoteHibernationMarkerStore;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('writes the awake and cold markers after preparing the marker and log directories', function (string $marker): void {
    $agents = new FakeAgentCommandRunner()->answer('hibernation.marker', []);
    $store = new RemoteHibernationMarkerStore($agents);
    $key = RuntimeHibernation::key(6);

    $marker === 'awake' ? $store->markAwake(hibernation_marker_node(), $key) : $store->markCold(hibernation_marker_node(), $key);

    expect($agents->calls[0]['input'])->toBe([
        'operation' => 'mark',
        'marker_directory' => RuntimeHibernation::MarkerDirectory,
        'log_directory' => RuntimeHibernation::AccessLogDirectory,
        'path' => $marker === 'awake' ? RuntimeHibernation::awakePath($key) : RuntimeHibernation::coldPath($key),
    ]);
})->with(['awake', 'cold']);

it('removes the awake and cold markers', function (): void {
    $agents = new FakeAgentCommandRunner()->answer('hibernation.marker', []);
    $store = new RemoteHibernationMarkerStore($agents);
    $key = RuntimeHibernation::key(6);

    $store->markAsleep(hibernation_marker_node(), $key);
    $store->clearCold(hibernation_marker_node(), $key);

    expect(array_column($agents->calls, 'input'))->toBe([
        ['operation' => 'remove', 'path' => RuntimeHibernation::awakePath($key)],
        ['operation' => 'remove', 'path' => RuntimeHibernation::coldPath($key)],
    ]);
});

it('uses the later of the access log and awake marker as last activity, and none when both are missing', function (?int $log, ?int $awake, ?int $activity): void {
    $key = RuntimeHibernation::key(6);
    $agents = new FakeAgentCommandRunner()->answer('hibernation.times', ['times' => [
        RuntimeHibernation::accessLogPath($key) => $log,
        RuntimeHibernation::awakePath($key) => $awake,
    ]]);

    expect(new RemoteHibernationMarkerStore($agents)->lastActivityUnix(hibernation_marker_node(), $key))->toBe($activity)
        ->and($agents->calls)->toHaveCount(1);
})->with([[100, 200, 200], [300, null, 300], [null, null, null]]);

it('reads a present awake or cold marker, and a Node that does not answer as absent', function (): void {
    $key = RuntimeHibernation::key(6);
    $present = new FakeAgentCommandRunner()->answer('hibernation.times', ['times' => [RuntimeHibernation::awakePath($key) => 100, RuntimeHibernation::coldPath($key) => null]]);
    $silent = new FakeAgentCommandRunner()->answer('hibernation.times', fn () => throw new RuntimeException('unreachable'));

    expect(new RemoteHibernationMarkerStore($present)->isAwake(hibernation_marker_node(), $key))->toBeTrue()
        ->and(new RemoteHibernationMarkerStore($present)->isCold(hibernation_marker_node(), $key))->toBeFalse()
        ->and(new RemoteHibernationMarkerStore($silent)->isAwake(hibernation_marker_node(), $key))->toBeFalse();
});

function hibernation_marker_node(): Node
{
    return new Node(['name' => 'app-dev', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3']);
}
