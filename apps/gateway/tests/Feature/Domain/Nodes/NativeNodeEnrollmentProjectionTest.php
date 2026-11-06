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

    $roles = json_decode(json_encode(app(NativeNodeEnrollmentProjection::class)->input($operation), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR)->intent->roles;

    expect($roles[0]->execution_secrets)->toEqual(new stdClass)
        ->and((array) $roles[1]->execution_secrets->environment)->toBe($copy);
});

it('passes an intent without roles through unchanged', function (): void {
    $operation = (new Operation)->forceFill(['input' => ['intent' => ['platform' => 'linux'], 'execution_secrets' => []]]);

    expect(app(NativeNodeEnrollmentProjection::class)->input($operation))->toBe(['intent' => ['platform' => 'linux'], 'execution_secrets' => []]);
});
