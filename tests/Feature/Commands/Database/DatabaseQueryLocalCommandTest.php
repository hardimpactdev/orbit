<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function createQueryLocalSqliteDatabase(): string
{
    $path = tempnam(sys_get_temp_dir(), 'orbit-query-local-');
    $pdo = new PDO("sqlite:{$path}");
    $pdo->exec('create table users (id integer primary key autoincrement, name text not null)');

    foreach (range(1, 60) as $index) {
        $statement = $pdo->prepare('insert into users (name) values (:name)');
        $statement->execute(['name' => "User {$index}"]);
    }

    return $path;
}

function runDatabaseQueryLocal(array $payload): array
{
    return runDatabaseQueryLocalWithInput(json_encode($payload, JSON_THROW_ON_ERROR));
}

function runDatabaseQueryLocalWithInput(string $stdin): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput([]);
    $input->setStream($stream);
    $output = new BufferedOutput;
    $exitCode = Artisan::all()['database:query-local']->run($input, $output);
    $stdout = $output->fetch();

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'payload' => strictDatabaseQueryLocalPayload($stdout),
    ];
}

function strictDatabaseQueryLocalPayload(string $stdout): array
{
    expect($stdout)->toMatch('/^\{.*\}\n?$/s');

    $payload = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($payload))->toHaveCount(1)
        ->and(array_key_first($payload))->toBeIn(['success', 'error']);

    return $payload;
}

describe('database:query-local', function (): void {
    it('is hidden from the command list', function (): void {
        $command = Artisan::all()['database:query-local'] ?? null;

        expect($command)->not->toBeNull()
            ->and($command->isHidden())->toBeTrue();
    });

    it('executes readonly sqlite queries from a stdin payload and applies the default row limit', function (): void {
        $path = createQueryLocalSqliteDatabase();

        $result = runDatabaseQueryLocal([
            'connection' => [
                'driver' => 'sqlite',
                'path' => $path,
                'credentials' => ['password' => 'never-print-me'],
            ],
            'sql' => 'select id, name from users order by id',
        ]);

        expect($result['exit_code'])->toBe(0)
            ->and($result['payload'])->toHaveKey('success')
            ->and($result['payload'])->not->toHaveKey('error')
            ->and($result['payload']['success']['data']['rows'])->toHaveCount(50)
            ->and($result['payload']['success']['data']['columns'])->toBe(['id', 'name'])
            ->and($result['payload']['success']['meta']['limit'])->toBe(50)
            ->and($result['payload']['success']['meta']['returned_rows'])->toBe(50)
            ->and($result['payload']['success']['meta']['truncated'])->toBeTrue()
            ->and($result['payload']['success']['meta']['truncated_by'])->toBe(['limit'])
            ->and($result['stdout'])->not->toContain('never-print-me');
    });

    it('caps non-full queries at 500 rows', function (): void {
        $path = createQueryLocalSqliteDatabase();

        $result = runDatabaseQueryLocal([
            'connection' => [
                'driver' => 'sqlite',
                'path' => $path,
            ],
            'sql' => 'select id, name from users order by id',
            'limit' => 900,
        ]);

        expect($result['exit_code'])->toBe(0)
            ->and($result['payload']['success']['meta']['limit'])->toBe(500)
            ->and($result['payload']['success']['meta']['returned_rows'])->toBe(60)
            ->and($result['payload']['success']['meta']['truncated'])->toBeFalse();
    });

    it('caps full queries at 10000 rows', function (): void {
        $path = createQueryLocalSqliteDatabase();

        $result = runDatabaseQueryLocal([
            'connection' => [
                'driver' => 'sqlite',
                'path' => $path,
            ],
            'sql' => 'select id, name from users order by id',
            'limit' => 50000,
            'full' => true,
        ]);

        expect($result['exit_code'])->toBe(0)
            ->and($result['payload']['success']['meta']['limit'])->toBe(10000);
    });

    it('truncates read results by json size', function (): void {
        $path = createQueryLocalSqliteDatabase();

        $result = runDatabaseQueryLocal([
            'connection' => [
                'driver' => 'sqlite',
                'path' => $path,
            ],
            'sql' => 'select id, name from users order by id',
            'limit' => 60,
            'max_json_bytes' => 240,
        ]);

        expect($result['exit_code'])->toBe(0)
            ->and($result['payload']['success']['meta']['returned_rows'])->toBeLessThan(60)
            ->and($result['payload']['success']['meta']['truncated'])->toBeTrue()
            ->and($result['payload']['success']['meta']['truncated_by'])->toContain('json_size');
    });

    it('rejects writes unless write mode is explicit', function (): void {
        $path = createQueryLocalSqliteDatabase();

        $result = runDatabaseQueryLocal([
            'connection' => [
                'driver' => 'sqlite',
                'path' => $path,
            ],
            'sql' => 'update users set name = "Changed" where id = 1',
        ]);

        expect($result['exit_code'])->toBe(1)
            ->and($result['payload'])->toHaveKey('error')
            ->and($result['payload']['error']['code'])->toBe('database_query.write_not_allowed');
    });

    it('rejects write cte statements unless write mode is explicit', function (): void {
        $path = createQueryLocalSqliteDatabase();

        $result = runDatabaseQueryLocal([
            'connection' => [
                'driver' => 'sqlite',
                'path' => $path,
            ],
            'sql' => 'with changed as (update users set name = "Changed" where id = 1 returning *) select * from changed',
        ]);

        expect($result['exit_code'])->toBe(1)
            ->and($result['payload'])->toHaveKey('error')
            ->and($result['payload']['error']['code'])->toBe('database_query.write_not_allowed');
    });

    it('executes writes when write mode is explicit', function (): void {
        $path = createQueryLocalSqliteDatabase();

        $result = runDatabaseQueryLocal([
            'connection' => [
                'driver' => 'sqlite',
                'path' => $path,
            ],
            'sql' => 'update users set name = "Changed" where id = 1',
            'write' => true,
        ]);

        expect($result['exit_code'])->toBe(0)
            ->and($result['payload']['success']['data']['affected_rows'])->toBe(1)
            ->and($result['payload']['success']['data'])->not->toHaveKey('rows');
    });

    it('emits json errors for invalid stdin payloads', function (): void {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, 'not-json');
        rewind($stream);

        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;
        $exitCode = Artisan::all()['database:query-local']->run($input, $output);
        $stdout = $output->fetch();
        $payload = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('emits json errors for empty stdin and missing connection payloads', function (): void {
        $empty = runDatabaseQueryLocalWithInput('');
        $missingConnection = runDatabaseQueryLocalWithInput(json_encode(['sql' => 'select 1'], JSON_THROW_ON_ERROR));

        expect($empty['exit_code'])->toBe(1)
            ->and($empty['payload']['error']['code'])->toBe('validation_failed')
            ->and($missingConnection['exit_code'])->toBe(1)
            ->and($missingConnection['payload']['error']['code'])->toBe('validation_failed');
    });

    it('sanitizes execution failure output and omits connection secrets', function (): void {
        $path = createQueryLocalSqliteDatabase();

        $result = runDatabaseQueryLocal([
            'connection' => [
                'driver' => 'sqlite',
                'path' => $path,
                'credentials' => ['password' => 'never-print-me'],
            ],
            'sql' => 'select * from missing_table',
        ]);

        expect($result['exit_code'])->toBe(1)
            ->and($result['payload']['error']['code'])->toBe('database_query.execution_failed')
            ->and($result['payload']['error']['message'])->toBe('Database query execution failed.')
            ->and($result['stdout'])->not->toContain('missing_table')
            ->and($result['stdout'])->not->toContain('never-print-me');
    });
});
