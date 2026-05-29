<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('database write commands', function (): void {
    it('posts database:add payloads to the gateway without printing secrets', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connection' => [
                'slug' => 'primary-db',
                'driver' => 'pgsql',
                'host' => '10.6.0.20',
                'database' => 'orbit',
                'username' => 'orbit',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'database:add', [
            'slug' => 'primary-db',
            '--driver' => 'pgsql',
            '--node' => 'db-node',
            '--host' => '10.6.0.20',
            '--port' => '5432',
            '--database' => 'orbit',
            '--username' => 'orbit',
            '--password' => 'super-secret',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/database-connections')
                && $request->data() === [
                    'slug' => 'primary-db',
                    'driver' => 'pgsql',
                    'node' => 'db-node',
                    'host' => '10.6.0.20',
                    'port' => '5432',
                    'database' => 'orbit',
                    'username' => 'orbit',
                    'password' => 'super-secret',
                ];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['connection']['slug'])->toBe('primary-db')
            ->and($output)->not->toContain('super-secret');
    });

    it('patches database:update payloads and supports clearing stored passwords', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connection' => [
                'slug' => 'renamed-db',
                'driver' => 'pgsql',
            ],
        ]));

        [$exitCode] = runCommand($this, 'database:update', [
            'connection' => 'primary-db',
            '--slug' => 'renamed-db',
            '--clear-password' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), '/api/database-connections/primary-db')
                && $request->data() === [
                    'slug' => 'renamed-db',
                    'clear_password' => true,
                ];
        });

        expect($exitCode)->toBe(0);
    });

    it('rejects database:update without mutable fields before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'database:update', [
            'connection' => 'primary-db',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('payload');
    });

    it('attaches database connections to app targets', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connection' => [
                'slug' => 'primary-db',
                'targets' => [['type' => 'app', 'name' => 'docs', 'env_prefix' => 'DB']],
            ],
        ]));

        [$exitCode] = runCommand($this, 'database:attach', [
            'connection' => 'primary-db',
            '--app' => 'docs',
            '--env-prefix' => 'DB',
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/database-connections/primary-db/targets')
                && $request->data() === [
                    'app' => 'docs',
                    'env_prefix' => 'DB',
                ];
        });

        expect($exitCode)->toBe(0);
    });

    it('detaches database connections from workspace targets', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => [
                'action' => 'detached',
                'connection' => 'primary-db',
                'target_type' => 'workspace',
                'target' => 'feature-docs',
                'env_prefix' => 'REPORTING',
            ],
        ]));

        [$exitCode] = runCommand($this, 'database:detach', [
            'connection' => 'primary-db',
            '--workspace' => 'feature-docs',
            '--env-prefix' => 'REPORTING',
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/database-connections/primary-db/targets')
                && $request->data() === [
                    'workspace' => 'feature-docs',
                    'env_prefix' => 'REPORTING',
                ];
        });

        expect($exitCode)->toBe(0);
    });

    it('rejects conflicting attach scopes before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'database:attach', [
            'connection' => 'primary-db',
            '--app' => 'docs',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('scope');
    });

    it('validates required database write inputs before contacting the gateway', function (string $command, array $params, string $field): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, $command, [
            ...$params,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe($field);
    })->with([
        'add slug' => ['database:add', ['--driver' => 'pgsql'], 'slug'],
        'attach connection' => ['database:attach', ['--app' => 'docs'], 'connection'],
        'attach target scope' => ['database:attach', ['connection' => 'primary-db'], 'scope'],
        'detach target scope' => ['database:detach', ['connection' => 'primary-db'], 'scope'],
        'query target' => ['database:query', ['--sql' => 'select 1'], 'target'],
    ]);

    it('does not leak database passwords in validation failures', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'database:add', [
            '--driver' => 'pgsql',
            '--password' => 'super-secret',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('slug')
            ->and($output)->not->toContain('super-secret');
    });

    it('requires force for database:remove before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'database:remove', [
            'connection' => 'primary-db',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta'])->toMatchArray([
                'field' => 'force',
                'reason' => 'destructive_consent_required',
            ]);
    });

    it('deletes database connections when force is supplied', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => [
                'action' => 'removed',
                'connection' => 'primary-db',
            ],
        ]));

        [$exitCode] = runCommand($this, 'database:remove', [
            'connection' => 'primary-db',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/api/database-connections/primary-db')
                && $request->data() === ['force' => true];
        });

        expect($exitCode)->toBe(0);
    });

    it('prompts before removing a database connection without force in interactive mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'result' => [
                'action' => 'removed',
                'connection' => 'primary-db',
            ],
        ]));

        $this->artisan('database:remove', ['connection' => 'primary-db'])
            ->expectsConfirmation('Remove database connection and all target mappings?', 'yes')
            ->expectsOutputToContain('removed')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/database-connections/primary-db')
            && $request->data() === ['force' => true]);
    });

    it('posts database:query payloads and emits strict JSON without requiring --json', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'columns' => ['id'],
            'rows' => [['id' => 1]],
            'row_count' => 1,
        ], [
            'connection' => 'primary-db',
            'driver' => 'pgsql',
            'elapsed_ms' => 12,
        ]));

        [$exitCode, $output] = runCommand($this, 'database:query', [
            'target' => 'docs',
            '--sql' => 'select * from users',
            '--connection' => 'primary-db',
            '--write' => true,
            '--full' => true,
            '--limit' => '100',
            '--timeout' => '30',
            '--max-json-bytes' => '8192',
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/database-connections/query')
                && $request->data() === [
                    'target' => 'docs',
                    'sql' => 'select * from users',
                    'connection' => 'primary-db',
                    'write' => true,
                    'full' => true,
                    'limit' => '100',
                    'timeout' => '30',
                    'max_json_bytes' => '8192',
                ];
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['rows'][0]['id'])->toBe(1)
            ->and($output)->toStartWith('{');
    });

    it('requires SQL for database:query before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'database:query', [
            'target' => 'docs',
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('sql');
    });

    it('passes through gateway errors from database writes', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'This node is not authorized to manage database connections.'), 403);

        [$exitCode, $output] = runCommand($this, 'database:add', [
            'slug' => 'primary-db',
            '--driver' => 'pgsql',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed');
    });
});
