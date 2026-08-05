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
            '--instance' => 'docs.production',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/database-connections')
                && str_contains($url, 'instance=docs.production')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['connections'][0]['slug'])
            ->toBe('primary-db')
            ->and($decoded['success']['meta']['count'])
            ->toBe(1);
    });

    it('rejects combined instance and workspace scope before calling the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope(['connections' => []]));

        [$exitCode, $output] = runCommand($this, 'database:list', [
            '--instance' => 'docs.production',
            '--workspace' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('scope');
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

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data'])
            ->toHaveKey('connections')
            ->and($decoded['success']['data'])
            ->not->toHaveKey('password')->and($decoded['success']['data'])
            ->not->toHaveKey('operation_token')->and($decoded['success']['meta'])->toBe(['count' => 1])->and($output)
            ->not->toContain('must-not-leak');
    });

    it('passes through gateway error codes from HTTP failures', function (): void {
        fakeGateway(
            fakeErrorEnvelope('authorization_failed', 'This node is not authorized to read database connections.'),
            403,
        );

        [$exitCode, $output] = runCommand($this, 'database:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('authorization_failed');
    });

    it('renders human output as a table with documented columns', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connections' => [
                [
                    'slug' => 'primary-db',
                    'driver' => 'pgsql',
                    'node' => 'db-node',
                    'targets' => [
                        ['type' => 'app', 'name' => 'docs', 'env_prefix' => 'DB'],
                        ['type' => 'workspace', 'name' => 'feature-docs', 'env_prefix' => 'DB'],
                    ],
                ],
                [
                    'slug' => 'sqlite-db',
                    'driver' => 'sqlite',
                    'node' => null,
                    'targets' => [],
                ],
            ],
        ], ['count' => 2]));

        [$exitCode, $output] = runCommand($this, 'database:list');

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Showing 2 database connection(s).')
            ->and($output)
            ->toContain('SLUG')
            ->and($output)
            ->toContain('DRIVER')
            ->and($output)
            ->toContain('NODE')
            ->and($output)
            ->toContain('TARGETS')
            ->and($output)
            ->toContain('primary-db')
            ->and($output)
            ->toContain('pgsql')
            ->and($output)
            ->toContain('db-node')
            ->and($output)
            ->toContain('docs')
            ->and($output)
            ->toContain('feature-docs')
            ->and($output)
            ->toContain('sqlite-db')
            ->and($output)
            ->toContain('—')
            ->and($output)
            ->not->toContain('connections: [')->and($output)
            ->not->toContain('"env_prefix"');
    });

    it('renders the documented empty-state line when no connections match', function (): void {
        fakeGateway(fakeSuccessEnvelope(['connections' => []], ['count' => 0]));

        [$exitCode, $output] = runCommand($this, 'database:list');

        expect($exitCode)->toBe(0)->and($output)->toBe('No database connections matched this scope.');
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

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['connection']['slug'])->toBe('primary-db');
    });

    it('requires the connection argument in JSON mode', function (): void {
        [$exitCode, $output] = runCommand($this, 'database:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('connection');
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

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data'])
            ->toHaveKey('connection')
            ->and($decoded['success']['data'])
            ->not->toHaveKey('password')->and($decoded['success']['data'])
            ->not->toHaveKey('operation_token')->and($decoded['success']['meta'])->toBe([])->and($output)
            ->not->toContain('must-not-leak');
    });

    it('renders human output as a show-detail tree with documented labels', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connection' => [
                'slug' => 'ditis-hr',
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => 5434,
                'database' => 'ditis_hr',
                'path' => null,
                'username' => 'orbit',
                'node' => 'beast',
                'targets' => [
                    [
                        'type' => 'instance',
                        'app' => 'ditis-hr',
                        'instance' => 'development',
                        'env_prefix' => 'DB',
                    ],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'database:show', ['connection' => 'ditis-hr']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('┌  Database connection: ditis-hr')
            ->and($output)
            ->toContain('Driver')
            ->and($output)
            ->toContain('pgsql')
            ->and($output)
            ->toContain('Host')
            ->and($output)
            ->toContain('127.0.0.1')
            ->and($output)
            ->toContain('Port')
            ->and($output)
            ->toContain('5434')
            ->and($output)
            ->toContain('Name')
            ->and($output)
            ->toContain('ditis_hr')
            ->and($output)
            ->toContain('User')
            ->and($output)
            ->toContain('orbit')
            ->and($output)
            ->toContain('Targets')
            ->and($output)
            ->toContain('ditis-hr.development')
            ->and($output)
            ->toContain('Node')
            ->and($output)
            ->toContain('beast')
            ->and($output)
            ->not->toContain('connection: {')->and($output)
            ->not->toContain('"env_prefix"');
    });

    it('renders Path for sqlite connections and em dash for missing values', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'connection' => [
                'slug' => 'local-sqlite',
                'driver' => 'sqlite',
                'host' => null,
                'port' => null,
                'database' => null,
                'path' => '/srv/app/database.sqlite',
                'username' => null,
                'node' => 'beast',
                'targets' => [],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'database:show', ['connection' => 'local-sqlite']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('┌  Database connection: local-sqlite')
            ->and($output)
            ->toContain('Path')
            ->and($output)
            ->toContain('/srv/app/database.sqlite')
            ->and($output)
            ->toContain('—');
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

            return (
                str_contains($url, '/api/database-connections/tables')
                && str_contains($url, 'target=docs')
                && str_contains($url, 'connection=primary-db')
            );
        });

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['rows'][0]['name'])->toBe('users');
    });

    it('requires the target argument in JSON mode', function (): void {
        [$exitCode, $output] = runCommand($this, 'database:tables', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('target');
    });

    it('renders human output as a table of driver-reported rows', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'columns' => ['name', 'kind'],
            'rows' => [
                ['name' => 'users', 'kind' => 'base_table'],
                ['name' => 'sessions', 'kind' => 'base_table'],
            ],
        ], ['connection' => 'primary-db', 'driver' => 'pgsql']));

        [$exitCode, $output] = runCommand($this, 'database:tables', ['target' => 'docs']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Showing 2 table(s).')
            ->and($output)
            ->toContain('NAME')
            ->and($output)
            ->toContain('KIND')
            ->and($output)
            ->toContain('users')
            ->and($output)
            ->toContain('sessions')
            ->and($output)
            ->toContain('base_table')
            ->and($output)
            ->not->toContain('rows: [')->and($output)
            ->not->toContain('"kind"');
    });

    it('renders an empty table count when no tables are returned', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'columns' => ['name'],
            'rows' => [],
        ], ['connection' => 'primary-db', 'driver' => 'pgsql']));

        [$exitCode, $output] = runCommand($this, 'database:tables', ['target' => 'docs']);

        expect($exitCode)->toBe(0)->and($output)->toContain('Showing 0 table(s).');
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

            return (
                str_contains($url, '/api/database-connections/schema')
                && str_contains($url, 'target=docs')
                && str_contains($url, 'connection=primary-db')
            );
        });

        expect($exitCode)->toBe(0);
    });

    it('requires the target argument in JSON mode', function (): void {
        [$exitCode, $output] = runCommand($this, 'database:schema', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('target');
    });

    it('renders human output as a table of schema metadata rows', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'columns' => ['table_name', 'table_type'],
            'rows' => [
                ['table_name' => 'users', 'table_type' => 'BASE TABLE'],
                ['table_name' => 'reports', 'table_type' => 'VIEW'],
            ],
        ], ['connection' => 'primary-db', 'driver' => 'pgsql']));

        [$exitCode, $output] = runCommand($this, 'database:schema', ['target' => 'docs']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Showing schema metadata for database connection 'primary-db'.")
            ->and($output)
            ->toContain('TABLE_NAME')
            ->and($output)
            ->toContain('TABLE_TYPE')
            ->and($output)
            ->toContain('users')
            ->and($output)
            ->toContain('reports')
            ->and($output)
            ->toContain('VIEW')
            ->and($output)
            ->not->toContain('rows: [')->and($output)
            ->not->toContain('"table_type"');
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

            return (
                str_contains($url, '/api/database-connections/describe')
                && str_contains($url, 'target=docs')
                && str_contains($url, 'table=users')
                && str_contains($url, 'connection=primary-db')
            );
        });

        expect($exitCode)->toBe(0);
    });

    it('requires the table argument in JSON mode', function (): void {
        [$exitCode, $output] = runCommand($this, 'database:describe', [
            'target' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('table');
    });

    it('renders human output as a table of column metadata rows', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'columns' => ['column_name', 'data_type', 'is_nullable', 'column_default'],
            'rows' => [
                ['column_name' => 'id', 'data_type' => 'integer', 'is_nullable' => 'NO', 'column_default' => null],
                ['column_name' => 'email', 'data_type' => 'varchar', 'is_nullable' => 'NO', 'column_default' => null],
            ],
        ], ['connection' => 'primary-db', 'driver' => 'pgsql']));

        [$exitCode, $output] = runCommand($this, 'database:describe', [
            'target' => 'docs',
            'table' => 'users',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("Showing description for table 'users'.")
            ->and($output)
            ->toContain('COLUMN_NAME')
            ->and($output)
            ->toContain('DATA_TYPE')
            ->and($output)
            ->toContain('IS_NULLABLE')
            ->and($output)
            ->toContain('COLUMN_DEFAULT')
            ->and($output)
            ->toContain('id')
            ->and($output)
            ->toContain('email')
            ->and($output)
            ->toContain('integer')
            ->and($output)
            ->toContain('—')
            ->and($output)
            ->not->toContain('rows: [')->and($output)
            ->not->toContain('"data_type"');
    });
});
