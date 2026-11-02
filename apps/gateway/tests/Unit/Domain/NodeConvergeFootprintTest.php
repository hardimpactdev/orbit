<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeProjection;

function convergeFootprint(array $names, array $steps, string $status = 'succeeded', array $role = [], bool $firstVisit = false): array
{
    $snapshot = ['first_visit' => $firstVisit, 'artifacts' => array_map(static fn (string $name): array => ['name' => $name, 'required' => true, 'repair' => false], $names)];
    $native = ['steps' => array_map(static fn (string $s): array => ['status' => $s], $steps), ...($role === [] ? [] : ['role' => ['result' => ['stdout' => json_encode(['artifacts' => $role])]]])];

    return (new ReflectionMethod(NodeConvergeProjection::class, 'footprint'))
        ->invoke(new NodeConvergeProjection, $snapshot, ['status' => $status, 'error_code' => $status === 'succeeded' ? null : 'operation.native_failed'], $native);
}

it('applies every part when the chart steps and the role child succeeded', function (): void {
    [$data, $result] = convergeFootprint(['agent', 'peer-dns', 'tmpfiles'], ['Restart the Node agent' => 'succeeded', 'Apply private DNS policy' => 'succeeded',
        'Re-apply role files' => 'succeeded', 'Check the footprint' => 'succeeded', 'Prove the footprint' => 'succeeded'], role: [['name' => 'tmpfiles', 'status' => 'applied']]);

    expect($data['status'])->toBe('succeeded')
        ->and($result)->toBe(['artifacts' => [['name' => 'agent', 'status' => 'applied'], ['name' => 'peer-dns', 'status' => 'applied'], ['name' => 'tmpfiles', 'status' => 'applied']],
            'footprint_proven' => true]);
});

it('keeps role parts at their old digest when the role child failed, and records the agent', function (): void {
    [, $result] = convergeFootprint(['agent', 'caddy', 'tmpfiles'], ['Restart the Node agent' => 'succeeded', 'Re-apply role files' => 'failed'], 'failed');

    expect($result)->toBe(['artifacts' => [['name' => 'agent', 'status' => 'applied']], 'footprint_proven' => false, 'failed_artifact' => 'caddy', 'error_code' => 'operation.native_failed']);
});

it('records nothing for the agent when its key does not match the recorded verifier', function (): void {
    [, $result] = convergeFootprint(['agent'], ['Restart the Node agent' => 'succeeded', 'Check the footprint' => 'succeeded', 'Prove the footprint' => 'failed'], 'failed');

    expect($result['failed_artifact'])->toBe('agent')->and($result['artifacts'])->toBe([]);
});

it('fails a succeeded run whose Caddyfile was refused on a first visit', function (): void {
    [$data, $result] = convergeFootprint(['agent', 'caddy'], ['Restart the Node agent' => 'succeeded', 'Re-apply role files' => 'succeeded', 'Prove the footprint' => 'succeeded'],
        role: [['name' => 'caddy', 'status' => 'skipped', 'reason' => 'caddy_validate_refused']], firstVisit: true);

    expect($data)->toMatchArray(['status' => 'failed', 'error_code' => 'node.footprint_caddy_failed'])
        ->and($result['footprint_proven'])->toBeFalse();
});
