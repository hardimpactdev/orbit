<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal database query local command', function (): void {
    beforeEach(function (): void {
        configureDatabaseQueryLocalOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = runInternalDatabaseQueryLocalCommand(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects an invalid operation token before reading stdin', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance('App\Services\GatewayApiClient');
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');

        [$exitCode, $output] = runInternalDatabaseQueryLocalCommand([
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('executes readonly sqlite queries from a stdin payload', function (): void {
        $path = createInternalDatabaseQueryLocalSqliteDatabase();

        [$exitCode, $output] = runInternalDatabaseQueryLocalCommand(
            [
                '--operation-token' => databaseQueryLocalSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'connection' => [
                    'driver' => 'sqlite',
                    'path' => $path,
                    'credentials' => ['password' => 'never-print-me'],
                ],
                'sql' => 'select id, name from users order by id',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data'])
            ->toBe([
                'columns' => ['id', 'name'],
                'rows' => [
                    ['id' => 1, 'name' => 'Ada'],
                    ['id' => 2, 'name' => 'Grace'],
                ],
            ])
            ->and($payload['success']['meta'])
            ->toMatchArray([
                'mode' => 'read',
                'limit' => 50,
                'total_rows' => 2,
                'returned_rows' => 2,
                'truncated' => false,
                'truncated_by' => [],
                'max_json_bytes' => 1048576,
            ])
            ->and($output)
            ->not->toContain('never-print-me');
    });

    it('rejects writes unless write mode is explicit and leaves the database unchanged', function (): void {
        $path = createInternalDatabaseQueryLocalSqliteDatabase();

        [$exitCode, $output] = runInternalDatabaseQueryLocalCommand(
            [
                '--operation-token' => databaseQueryLocalSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'connection' => [
                    'driver' => 'sqlite',
                    'path' => $path,
                ],
                'sql' => 'update users set name = "Changed" where id = 1',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $database = new PDO("sqlite:{$path}");
        $name = $database->query('select name from users where id = 1')->fetchColumn();

        expect($exitCode)
            ->toBe(1)
            ->and($payload)
            ->toBe(JsonEnvelope::failure(
                'database_query.write_not_allowed',
                'This SQL statement requires explicit write mode.',
                ['mode' => 'write'],
            ))
            ->and($name)
            ->toBe('Ada');
    });

    it('executes writes when write mode is explicit', function (): void {
        $path = createInternalDatabaseQueryLocalSqliteDatabase();

        [$exitCode, $output] = runInternalDatabaseQueryLocalCommand(
            [
                '--operation-token' => databaseQueryLocalSignedOperationToken(),
                '--json' => true,
            ],
            json_encode([
                'connection' => [
                    'driver' => 'sqlite',
                    'path' => $path,
                ],
                'sql' => 'update users set name = "Changed" where id = 1',
                'write' => true,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $database = new PDO("sqlite:{$path}");
        $name = $database->query('select name from users where id = 1')->fetchColumn();

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data'])
            ->toBe(['affected_rows' => 1])
            ->and($payload['success']['meta']['mode'])
            ->toBe('write')
            ->and($name)
            ->toBe('Changed');
    });

    it('emits validation failures as strict json after token validation', function (): void {
        [$exitCode, $output] = runInternalDatabaseQueryLocalCommand([
            '--operation-token' => databaseQueryLocalSignedOperationToken(),
            '--json' => true,
        ], 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Database query payload is invalid.',
            ));
    });
});

function configureDatabaseQueryLocalOperationTokenGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function databaseQueryLocalSignedOperationToken(
    string $id = 'database-query-local',
    string $node = 'app-dev',
    string $command = 'internal:database-query-local',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'gateway-secret',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runInternalDatabaseQueryLocalCommand(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:database-query-local']->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

function createInternalDatabaseQueryLocalSqliteDatabase(): string
{
    $path = tempnam(sys_get_temp_dir(), 'orbit-internal-query-local-');
    $database = new PDO("sqlite:{$path}");
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('create table users (id integer primary key autoincrement, name text not null)');
    $database->exec("insert into users (name) values ('Ada'), ('Grace')");

    return $path;
}
