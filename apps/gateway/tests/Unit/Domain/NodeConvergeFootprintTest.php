<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeProjection;

function convergeFootprint(array $names, array $steps, string $status = 'succeeded', array $payloads = [], array $roles = [], bool $firstVisit = false): array
{
    $snapshot = ['first_visit' => $firstVisit, 'payloads' => $payloads, 'roles' => $roles,
        'artifacts' => array_map(static fn (string $name): array => ['name' => $name, 'required' => true, 'repair' => false], $names)];
    $native = ['steps' => array_map(static fn (string $s): array => ['status' => $s], $steps)];

    return (new ReflectionMethod(NodeConvergeProjection::class, 'footprint'))
        ->invoke(new NodeConvergeProjection, $snapshot, ['status' => $status, 'error_code' => $status === 'succeeded' ? null : 'operation.native_failed'], $native);
}

it('applies every part from its own step', function (): void {
    [$data, $result] = convergeFootprint(['agent', 'peer-dns', 'tmpfiles', 'php-runtime'], ['Restart the Node agent' => 'succeeded', 'Apply private DNS policy' => 'succeeded',
        'Write the tmpfiles rule' => 'succeeded', 'Configure PHP-FPM-8.4' => 'succeeded', 'Configure PHP-FPM-8.5' => 'succeeded',
        'Check the footprint' => 'succeeded', 'Prove the footprint' => 'succeeded'], roles: ['php_versions' => ['8.4', '8.5']]);

    expect($data['status'])->toBe('succeeded')
        ->and($result)->toBe(['artifacts' => [['name' => 'agent', 'status' => 'applied'], ['name' => 'peer-dns', 'status' => 'applied'],
            ['name' => 'tmpfiles', 'status' => 'applied'], ['name' => 'php-runtime', 'status' => 'applied']], 'footprint_proven' => true]);
});

it('keeps a failed part at its old digest and records the parts that applied', function (): void {
    [, $result] = convergeFootprint(['agent', 'caddy', 'tmpfiles', 'php-runtime'], ['Restart the Node agent' => 'succeeded', 'Check the footprint' => 'succeeded',
        'Prove the footprint' => 'succeeded', 'Publish Caddy sites' => 'failed', 'Write the tmpfiles rule' => 'succeeded',
        'Configure PHP-FPM-8.4' => 'succeeded', 'Configure PHP-FPM-8.5' => 'failed'], 'failed', roles: ['php_versions' => ['8.4', '8.5']]);

    expect($result)->toBe(['artifacts' => [['name' => 'agent', 'status' => 'applied'], ['name' => 'tmpfiles', 'status' => 'applied']],
        'footprint_proven' => false, 'failed_artifact' => 'caddy', 'error_code' => 'operation.native_failed']);
});

it('reports failed Route residue as skipped without failing the task', function (): void {
    [$data, $result] = convergeFootprint(['agent', 'route-residue'], ['Restart the Node agent' => 'succeeded', 'Prove the footprint' => 'succeeded',
        'Clear Route residue' => 'failed']);

    expect($data['status'])->toBe('succeeded')
        ->and($result['artifacts'][1])->toBe(['name' => 'route-residue', 'status' => 'skipped', 'reason' => 'route_residue_cleanup_failed']);
});

it('records nothing for the agent when its key does not match the recorded verifier', function (): void {
    [, $result] = convergeFootprint(['agent'], ['Restart the Node agent' => 'succeeded', 'Check the footprint' => 'succeeded', 'Prove the footprint' => 'failed'], 'failed');

    expect($result['failed_artifact'])->toBe('agent')->and($result['artifacts'])->toBe([]);
});

it('skips a Caddyfile the Gateway could not render, and fails a first visit for it', function (bool $firstVisit): void {
    [$data, $result] = convergeFootprint(['agent', 'caddy'], ['Restart the Node agent' => 'succeeded', 'Prove the footprint' => 'succeeded', 'Publish Caddy sites' => 'skipped'],
        payloads: ['caddy' => ['error_code' => 'caddy.render_failed', 'stage' => 'render']], roles: ['caddy' => null], firstVisit: $firstVisit);

    expect($result['artifacts'][1])->toBe(['name' => 'caddy', 'status' => 'skipped', 'reason' => 'caddy_render_refused'])
        ->and($data['status'])->toBe($firstVisit ? 'failed' : 'succeeded')
        ->and($result['footprint_proven'])->toBe(! $firstVisit);
})->with([true, false]);
