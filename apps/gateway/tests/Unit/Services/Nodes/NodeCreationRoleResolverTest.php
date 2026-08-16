<?php

declare(strict_types=1);

use App\Services\Nodes\NodeCreationRoleInputException;
use App\Services\Nodes\NodeCreationRoleResolver;
use App\Services\Nodes\Roles\NodeRoleRegistry;
use Tests\TestCase;

uses(TestCase::class);

it('treats omitted template and roles as a client identity request', function (): void {
    $selection = app(NodeCreationRoleResolver::class)->resolve(
        template: null,
        operator: false,
        roles: null,
    );

    expect($selection->clientIdentity)
        ->toBeTrue()
        ->and($selection->operator)
        ->toBeFalse()
        ->and($selection->gateway)
        ->toBeFalse()
        ->and($selection->workloadRoles)
        ->toBe([])
        ->and($selection->requestedRoleMeta)
        ->toBe('client');
});

it('expands node templates to canonical stored roles', function (
    string $template,
    array $workloadRoles,
    bool $gateway = false,
    bool $operator = false,
): void {
    $selection = app(NodeCreationRoleResolver::class)->resolve(
        template: $template,
        operator: false,
        roles: null,
    );

    expect($selection->template)
        ->toBe($template)
        ->and($selection->workloadRoles)
        ->toBe($workloadRoles)
        ->and($selection->gateway)
        ->toBe($gateway)
        ->and($selection->operator)
        ->toBe($operator);
})->with([
    'operator' => ['operator', [], false, true],
    'gateway' => ['gateway', [], true, false],
    'app development' => ['app-development', ['app-dev', 'database'], false, false],
    'app production' => ['app-production', ['app-prod'], false, false],
    'ingress' => ['ingress', ['ingress'], false, false],
    'database' => ['database', ['database'], false, false],
    's3' => ['s3', ['s3'], false, false],
    'metrics' => ['metrics', ['metrics'], false, false],
    'analytics' => ['analytics', ['analytics'], false, false],
    'agent' => ['agent', ['agent'], false, false],
]);

it('resolves comma-separated programmatic roles without template expansion', function (
    string $roles,
    array $workloadRoles,
    string $requestedRole,
): void {
    $selection = app(NodeCreationRoleResolver::class)->resolve(
        template: null,
        operator: false,
        roles: $roles,
    );

    expect($selection->template)
        ->toBeNull()
        ->and($selection->workloadRoles)
        ->toBe($workloadRoles)
        ->and($selection->requestedRoleMeta)
        ->toBe($requestedRole);
})->with([
    'app dev with database' => ['app-dev,database', ['app-dev', 'database'], 'app-dev'],
    'analytics with s3' => ['analytics,s3', ['analytics', 's3'], 'analytics'],
    'metrics' => ['metrics', ['metrics'], 'metrics'],
]);

it('rejects template and explicit roles together', function (): void {
    try {
        app(NodeCreationRoleResolver::class)->resolve(
            template: 'app-development',
            operator: false,
            roles: 'app-dev',
        );
    } catch (NodeCreationRoleInputException $exception) {
        expect($exception->errorCode)
            ->toBe('validation_failed')
            ->and($exception->getMessage())
            ->toBe('--template and --roles cannot be used together.')
            ->and($exception->meta)
            ->toBe(['fields' => ['template', 'roles']]);

        return;
    }

    $this->fail('Expected role input validation to fail.');
});

it('rejects retired aggregate role values without aliases', function (): void {
    try {
        app(NodeCreationRoleResolver::class)->resolve(
            template: null,
            operator: false,
            roles: 'app-development',
        );
    } catch (NodeCreationRoleInputException $exception) {
        expect($exception->errorCode)->toBe('validation_failed')->and($exception->meta)->toBe(['field' => 'roles']);

        return;
    }

    $this->fail('Expected retired role input to fail.');
});

it('uses registered validation failures for pending node templates', function (string $template): void {
    try {
        app(NodeCreationRoleResolver::class)->resolve(
            template: $template,
            operator: false,
            roles: null,
        );
    } catch (NodeCreationRoleInputException $exception) {
        expect($exception->errorCode)
            ->toBe('validation_failed')
            ->and($exception->meta)
            ->toBe([
                'field' => 'template',
                'reason' => 'not_implemented',
                'template' => $template,
            ]);

        return;
    }

    $this->fail('Expected the pending template to fail validation.');
})->with(['websocket']);

it('uses registered validation failures for pending explicit node roles', function (string $role): void {
    try {
        app(NodeCreationRoleResolver::class)->resolve(
            template: null,
            operator: false,
            roles: $role,
        );
    } catch (NodeCreationRoleInputException $exception) {
        expect($exception->errorCode)
            ->toBe('validation_failed')
            ->and($exception->meta)
            ->toBe([
                'field' => 'roles',
                'reason' => 'not_implemented',
                'role' => $role,
            ]);

        return;
    }

    $this->fail('Expected the pending role to fail validation.');
})->with(['websocket']);

it('rejects every registry conflict pair during explicit role resolution', function (): void {
    $registry = app(NodeRoleRegistry::class);
    $roles = [
        'app-dev',
        'app-prod',
        'database',
        'agent',
        'ingress',
        'websocket',
        's3',
        'metrics',
        'analytics',
    ];
    $rejectedPairs = [];

    foreach ($roles as $index => $firstRole) {
        foreach (array_slice($roles, $index + 1) as $secondRole) {
            $pair = $registry->firstConflictingRolePair([$firstRole, $secondRole]);

            if ($pair === null) {
                continue;
            }

            try {
                app(NodeCreationRoleResolver::class)->resolve(
                    template: null,
                    operator: false,
                    roles: implode(',', $pair),
                );
            } catch (NodeCreationRoleInputException $exception) {
                expect($exception->errorCode)
                    ->toBe('validation_failed')
                    ->and($exception->getMessage())
                    ->toBe("Workload roles {$pair[0]} and {$pair[1]} cannot be combined.")
                    ->and($exception->meta)
                    ->toBe([
                        'field' => 'roles',
                        'conflicts' => $pair,
                    ]);
                $rejectedPairs[] = implode('+', $pair);

                continue;
            }

            $this->fail("Expected roles {$pair[0]} and {$pair[1]} to conflict.");
        }
    }

    expect($rejectedPairs)->toHaveCount(18);
});

it('accepts every implemented compatible workload creation pair', function (): void {
    $registry = app(NodeRoleRegistry::class);
    $roles = [
        'app-dev',
        'app-prod',
        'database',
        'agent',
        'ingress',
        's3',
        'metrics',
        'analytics',
    ];
    $acceptedPairs = [];

    foreach ($roles as $index => $firstRole) {
        foreach (array_slice($roles, $index + 1) as $secondRole) {
            if ($registry->firstConflictingRolePair([$firstRole, $secondRole]) !== null) {
                continue;
            }

            $selection = app(NodeCreationRoleResolver::class)->resolve(
                template: null,
                operator: false,
                roles: "{$firstRole},{$secondRole}",
            );

            expect($selection->workloadRoles)->toBe([$firstRole, $secondRole]);
            $acceptedPairs[] = "{$firstRole}+{$secondRole}";
        }
    }

    expect($acceptedPairs)->toHaveCount(13);
});
