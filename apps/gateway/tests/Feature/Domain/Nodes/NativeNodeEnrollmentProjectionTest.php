<?php

declare(strict_types=1);

use App\Domain\Nodes\NativeNodeEnrollmentProjection;
use App\Models\Operation;

it('sends a role without copies with an empty record of copies, and keeps the copies of the others', function (): void {
    $copy = ['vault' => 'orbit-control-inputs', 'key' => 'node-role-operation-4-app-dev'];
    $operation = (new Operation)->forceFill(['input' => [
        'intent' => ['roles' => [
            ['kind' => 'node.role_add', 'intent' => ['role' => 'app-dev'], 'execution_secrets' => []],
            ['kind' => 'node.role_add', 'intent' => ['role' => 'app-prod'], 'execution_secrets' => ['environment' => $copy]],
        ]],
        'execution_secrets' => [],
    ]]);

    $input = json_encode(app(NativeNodeEnrollmentProjection::class)->input($operation), JSON_THROW_ON_ERROR);

    expect($input)->toContain('"intent":{"role":"app-dev"},"execution_secrets":{}')
        ->and($input)->toContain('"execution_secrets":{"environment":{"vault":"orbit-control-inputs"');
});

it('passes an intent without roles through unchanged', function (): void {
    $operation = (new Operation)->forceFill(['input' => ['intent' => ['platform' => 'linux'], 'execution_secrets' => []]]);

    expect(app(NativeNodeEnrollmentProjection::class)->input($operation))->toBe(['intent' => ['platform' => 'linux'], 'execution_secrets' => []]);
});
