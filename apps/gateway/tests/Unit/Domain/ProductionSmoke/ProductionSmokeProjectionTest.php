<?php

declare(strict_types=1);

use App\Domain\ProductionSmoke\ProductionSmokeProjection;
use App\Models\Operation;

function productionSmokeInputFixture(): Operation
{
    $change = ['source' => 'gateway_release', 'before_sha' => str_repeat('a', 40), 'after_sha' => str_repeat('b', 40),
        'changed_paths' => ['apps/gateway/app/Domain/Routes/Route.php'], 'previous_release_id' => str_repeat('a', 12),
        'orbit_sha' => str_repeat('b', 40), 'swamp_tree_sha' => str_repeat('c', 40), 'source_mirror_path' => '/fast/mirrors/orbit', 'merge_mainline' => 0];
    $site = ['instance_id' => 757, 'project_id' => 20, 'worker_node_id' => 9, 'mirror_path' => '/fast/mirrors/website',
        'commit' => str_repeat('d', 40), 'deployment_id' => 42, 'base_url' => 'https://nckrtl.com'];

    $operation = new Operation(['node_id' => 209, 'kind' => 'production.smoke', 'input' => ['change' => $change, 'sites' => [$site]]]);
    $operation->id = 123;

    return $operation;
}

it('passes complete admitted public intent as native workflow inputs', function (): void {
    $operation = productionSmokeInputFixture();

    expect((new ProductionSmokeProjection)->input($operation))->toBe([
        'intent' => $operation->input, 'execution_secrets' => [],
    ]);
});

it('does not forward private fields at any intent boundary', function (): void {
    $operation = productionSmokeInputFixture();
    $expected = (new ProductionSmokeProjection)->input($operation);
    $input = $operation->input;
    $input['environment'] = ['PRIVATE_VALUE' => 'fixture-private'];
    $input['change']['token'] = 'fixture-private';
    $input['sites'][0]['environment'] = ['PRIVATE_VALUE' => 'fixture-private'];
    $operation->input = $input;

    expect((new ProductionSmokeProjection)->input($operation))->toBe($expected);
});

it('refuses a missing or repeated site inventory before native fan-out', function (string $invalid): void {
    $operation = productionSmokeInputFixture();
    $input = $operation->input;
    $input['sites'] = $invalid === 'empty' ? [] : [$input['sites'][0], $input['sites'][0]];
    $operation->input = $input;

    expect(fn () => (new ProductionSmokeProjection)->input($operation))->toThrow(InvalidArgumentException::class);
})->with(['empty', 'duplicate']);

it('declares the shared full-input and terminal stdout contract', function (): void {
    expect(ProductionSmokeProjection::Declaration)->toBe([
        'workflow' => 'automatic-production-smoke', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'result', 'step' => 'result'],
        'projection' => ProductionSmokeProjection::class,
    ]);
});
