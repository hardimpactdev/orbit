<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('database:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connections' => [
                [
                    'slug' => 'primary-db',
                    'driver' => 'pgsql',
                    'node' => 'db-node',
                ],
            ],
        ], ['count' => 1]));

        [$exitCode, $output] = runCommand($this, 'database:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/database-connections')
                && str_contains($url, 'app=docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['connections'][0]['slug'])->toBe('primary-db')
            ->and($decoded['success']['meta']['count'])->toBe(1);
    });

    it('rejects combined app and workspace scope before calling the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope(['connections' => []]));

        [$exitCode, $output] = runCommand($this, 'database:list', [
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

    it('does not emit extra sensitive gateway envelope fields in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connections' => [
                ['slug' => 'primary-db', 'driver' => 'pgsql'],
            ],
            'password' => 'must-not-leak',
            'operation_token' => 'must-not-leak',
        ], [
            'count' => 1,
            'executor_secret' => 'must-not-leak',
            'database_url' => 'must-not-leak',
        ]));

        [$exitCode, $output] = runCommand($this, 'database:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data'])->toHaveKey('connections')
            ->and($decoded['success']['data'])->not->toHaveKey('password')
            ->and($decoded['success']['data'])->not->toHaveKey('operation_token')
            ->and($decoded['success']['meta'])->toBe(['count' => 1])
            ->and($output)->not->toContain('must-not-leak');
    });

    it('passes through gateway error codes from HTTP failures', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'This node is not authorized to read database connections.'), 403);

        [$exitCode, $output] = runCommand($this, 'database:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed');
    });
});

describe('database:show', function (): void {
    it('returns one connection in JSON mode and forwards the slug path', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connection' => [
                'slug' => 'primary-db',
                'driver' => 'pgsql',
                'username' => 'orbit',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'database:show', [
            'connection' => 'primary-db',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/api/database-connections/primary-db');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['connection']['slug'])->toBe('primary-db');
    });

    it('requires the connection argument in JSON mode', function (): void {
        [$exitCode, $output] = runCommand($this, 'database:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('connection');
    });

    it('does not emit extra sensitive gateway envelope fields in JSON mode', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connection' => [
                'slug' => 'primary-db',
                'driver' => 'pgsql',
            ],
            'password' => 'must-not-leak',
            'operation_token' => 'must-not-leak',
        ], [
            'executor_secret' => 'must-not-leak',
            'database_url' => 'must-not-leak',
        ]));

        [$exitCode, $output] = runCommand($this, 'database:show', [
            'connection' => 'primary-db',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data'])->toHaveKey('connection')
            ->and($decoded['success']['data'])->not->toHaveKey('password')
            ->and($decoded['success']['data'])->not->toHaveKey('operation_token')
            ->and($decoded['success']['meta'])->toBe([])
            ->and($output)->not->toContain('must-not-leak');
    });
});

describe('database:tables', function (): void {
    it('forwards target and connection query parameters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'rows' => [['name' => 'users']],
        ], ['connection' => 'primary-db']));

        [$exitCode, $output] = runCommand($this, 'database:tables', [
            'target' => 'docs',
            '--connection' => 'primary-db',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/database-connections/tables')
                && str_contains($url, 'target=docs')
                && str_contains($url, 'connection=primary-db');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['rows'][0]['name'])->toBe('users');
    });

    it('requires the target argument in JSON mode', function (): void {
        [$exitCode, $output] = runCommand($this, 'database:tables', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('target');
    });
});

describe('database:schema', function (): void {
    it('forwards target and connection query parameters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tables' => ['users'],
        ], ['connection' => 'primary-db']));

        [$exitCode, $output] = runCommand($this, 'database:schema', [
            'target' => 'docs',
            '--connection' => 'primary-db',
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/database-connections/schema')
                && str_contains($url, 'target=docs')
                && str_contains($url, 'connection=primary-db');
        });

        expect($exitCode)->toBe(0);
    });

    it('requires the target argument in JSON mode', function (): void {
        [$exitCode, $output] = runCommand($this, 'database:schema', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('target');
    });
});

describe('database:describe', function (): void {
    it('forwards target, table, and connection query parameters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'columns' => [['name' => 'id', 'type' => 'integer']],
        ], ['connection' => 'primary-db']));

        [$exitCode, $output] = runCommand($this, 'database:describe', [
            'target' => 'docs',
            'table' => 'users',
            '--connection' => 'primary-db',
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/database-connections/describe')
                && str_contains($url, 'target=docs')
                && str_contains($url, 'table=users')
                && str_contains($url, 'connection=primary-db');
        });

        expect($exitCode)->toBe(0);
    });

    it('requires the table argument in JSON mode', function (): void {
        [$exitCode, $output] = runCommand($this, 'database:describe', [
            'target' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('table');
    });
});
