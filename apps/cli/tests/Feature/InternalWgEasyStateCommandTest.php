<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Process\Process;

describe('internal wg-easy state command', function (): void {
    beforeEach(function (): void {
        configureWgEasyStateOperationTokenGuard();

        $this->wgEasyStateTemp = sys_get_temp_dir().'/orbit-cli-wg-easy-state-'.bin2hex(random_bytes(8));
        mkdir($this->wgEasyStateTemp, recursive: true);

        putenv("ORBIT_WG_EASY_DB_PATH={$this->wgEasyStateTemp}/wg-easy.db");
    });

    afterEach(function (): void {
        putenv('ORBIT_WG_EASY_DB_PATH');
        unset($_ENV['ORBIT_WG_EASY_DB_PATH'], $_SERVER['ORBIT_WG_EASY_DB_PATH']);

        removeWgEasyStateTempDirectory($this->wgEasyStateTemp);
    });

    it('rejects a missing operation token before resolving the database path', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => 'vpn.example.test',
            '--default-dns' => '["10.6.0.1"]',
            '--default-persistent-keepalive' => '25',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects an invalid operation token before opening the database', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => 'vpn.example.test',
            '--default-dns' => '["10.6.0.1"]',
            '--default-persistent-keepalive' => '25',
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('rejects an unsupported action before opening the database', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'evil',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'invalid_action',
                'wg-easy state action must be one of: update-user, update-general, ensure-writable, configure-peers.',
                [
                    'action' => 'evil',
                    'allowed' => ['update-user', 'update-general', 'ensure-writable', 'configure-peers'],
                ],
            ));
    });

    it('updates user config values with parameterized statements', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUserConfigDatabase($databasePath);

        $host = "vpn.example.test', default_dns = 'mutated";
        $defaultDns = '["10.6.0.1","1.1.1.1"]';

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => $host,
            '--default-dns' => $defaultDns,
            '--default-persistent-keepalive' => '25',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'user_configs_table');

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-user',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['host'])->toBe($host)
            ->and($row['default_dns'])->toBe($defaultDns)
            ->and($row['default_persistent_keepalive'])->toBe(25);
    });

    it('updates general setup step with a parameterized statement', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyGeneralDatabase($databasePath);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-general',
            '--setup-step' => '0',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'general_table');

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'update-general',
                    'updated' => true,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($row['setup_step'])->toBe(0);
    });

    it('configures peers with parameterized statements from a JSON payload', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyClientsDatabase($databasePath);

        $peers = [
            [
                'name' => "gateway-1', public_key = 'mutated",
                'private_key' => 'gateway-private',
                'public_key' => 'gateway-public',
                'pre_shared_key' => 'gateway-psk',
                'address' => '10.6.0.2',
            ],
            [
                'name' => 'control-1',
                'private_key' => 'control-private',
                'public_key' => 'control-public',
                'pre_shared_key' => 'control-psk',
                'address' => '10.6.0.3',
            ],
        ];

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'configure-peers',
            '--peers-json' => json_encode($peers, JSON_THROW_ON_ERROR),
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $pdo = createWgEasyStateWritableSqliteDatabase($databasePath);
        $rows = $pdo->query('select name, ipv4_address, ipv6_address, private_key, public_key, pre_shared_key, server_allowed_ips, dns from clients_table order by ipv4_address')->fetchAll(PDO::FETCH_ASSOC);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'configure-peers',
                    'configured' => 2,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ))
            ->and($rows)->toHaveCount(2)
            ->and($rows[0]['name'])->toBe("gateway-1', public_key = 'mutated")
            ->and($rows[0]['public_key'])->toBe('gateway-public')
            ->and($rows[0]['pre_shared_key'])->toBe('gateway-psk')
            ->and($rows[0]['ipv6_address'])->toBe('fdcc:ad94:bacf:61a4::cafe:2')
            ->and($rows[0]['server_allowed_ips'])->toBe('["10.6.0.2/32"]')
            ->and($rows[0]['dns'])->toBe('["10.6.0.1"]')
            ->and($rows[1]['public_key'])->toBe('control-public')
            ->and($rows[1]['pre_shared_key'])->toBe('control-psk');
    });

    it('returns success when the database file is writable', function (): void {
        createWgEasyGeneralDatabase("{$this->wgEasyStateTemp}/wg-easy.db");

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'ensure-writable',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toBe(json_encode(
                JsonEnvelope::success([
                    'action' => 'ensure-writable',
                    'writable' => true,
                    'ownership_changed' => false,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
    });

    it('returns a failure envelope when the database file is not writable and is outside the chown allowlist', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyGeneralDatabase($databasePath);
        chmod($databasePath, 0444);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'ensure-writable',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        chmod($databasePath, 0644);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'database_unwritable',
                'wg-easy database is not writable.',
                ['reason' => 'path_not_chown_eligible'],
            ));
    });

    it('rejects invalid update-user options before opening the database', function (array $options, string $field): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, array_merge([
            '--action' => 'update-user',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ], $options));

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'validation_failed',
                "The --{$field} option is invalid.",
                ['field' => $field],
            ));
    })->with([
        'non-string host' => [
            [
                '--host' => true,
                '--default-dns' => '["10.6.0.1"]',
                '--default-persistent-keepalive' => '25',
            ],
            'host',
        ],
        'missing default dns' => [
            [
                '--host' => 'vpn.example.test',
                '--default-persistent-keepalive' => '25',
            ],
            'default-dns',
        ],
        'non-integer keepalive' => [
            [
                '--host' => 'vpn.example.test',
                '--default-dns' => '["10.6.0.1"]',
                '--default-persistent-keepalive' => '25.5',
            ],
            'default-persistent-keepalive',
        ],
    ]);

    it('rejects invalid update-general options before opening the database', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-general',
            '--setup-step' => 'later',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The --setup-step option is invalid.',
                ['field' => 'setup-step'],
            ));
    });

    it('rejects host values with null bytes before writing to the database', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUserConfigDatabase($databasePath);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => "vpn\0evil",
            '--default-dns' => '["10.6.0.1"]',
            '--default-persistent-keepalive' => '25',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'user_configs_table');

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The --host option is invalid.',
                ['field' => 'host'],
            ))
            ->and($row['host'])->toBe('old.example.test');
    });

    it('rejects default DNS values with null bytes before writing to the database', function (): void {
        $databasePath = "{$this->wgEasyStateTemp}/wg-easy.db";
        createWgEasyUserConfigDatabase($databasePath);

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-user',
            '--host' => 'vpn.example.test',
            '--default-dns' => "1.1.1.1\0evil",
            '--default-persistent-keepalive' => '25',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        $row = readWgEasyStateRow($databasePath, 'user_configs_table');

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The --default-dns option is invalid.',
                ['field' => 'default-dns'],
            ))
            ->and($row['default_dns'])->toBe('["8.8.8.8"]');
    });

    it('rejects database path overrides with null bytes before file operations', function (): void {
        putenv('ORBIT_WG_EASY_DB_PATH');
        $_SERVER['ORBIT_WG_EASY_DB_PATH'] = "{$this->wgEasyStateTemp}/wg-easy.db\0evil";

        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'ensure-writable',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'validation_failed',
                'The ORBIT_WG_EASY_DB_PATH environment value is invalid.',
                ['field' => 'ORBIT_WG_EASY_DB_PATH'],
            ))
            ->and($output)->not->toContain('ValueError')
            ->and($output)->not->toContain('null byte');
    });

    it('returns a database_missing failure envelope without raw PDO details', function (): void {
        [$exitCode, $output] = runWgEasyStateCommand($this, [
            '--action' => 'update-general',
            '--setup-step' => '0',
            '--operation-token' => wgEasyStateSignedOperationToken(),
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe(JsonEnvelope::failure(
                'database_missing',
                'wg-easy database does not exist.',
            ))
            ->and($output)->not->toContain('SQLSTATE')
            ->and($output)->not->toContain('PDOException');
    });

    it('hides the internal wg-easy state command from php orbit list', function (): void {
        $process = new Process([PHP_BINARY, 'orbit', 'list'], base_path());
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->not->toContain('internal:wg-easy:state');
    });
});

function configureWgEasyStateOperationTokenGuard(): void
{
    config()->set('orbit.executor.shared_secret', 'gateway-secret');
    config()->set('orbit.executor.node_identity', 'app-dev');

    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function wgEasyStateSignedOperationToken(
    string $id = 'wg-easy-state',
    string $node = 'app-dev',
    string $command = 'internal:wg-easy:state',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return (new OperationTokenSigner)->sign(
        secret: 'gateway-secret',
        id: $id,
        node: $node,
        command: $command,
        issuedAt: $issuedAt,
        expiresAt: $expiresAt,
    )->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runWgEasyStateCommand(object $test, array $parameters = []): array
{
    $test->mockConsoleOutput = false;
    app()->offsetUnset(OutputStyle::class);

    $exitCode = $test->artisan('internal:wg-easy:state', $parameters);

    return [$exitCode, trim(app(Kernel::class)->output())];
}

function createWgEasyUserConfigDatabase(string $path): void
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec('create table user_configs_table (host text not null, default_dns text not null, default_persistent_keepalive integer not null)');
    $pdo->exec("insert into user_configs_table (host, default_dns, default_persistent_keepalive) values ('old.example.test', '[\"8.8.8.8\"]', 0)");
}

function createWgEasyGeneralDatabase(string $path): void
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec('create table general_table (setup_step integer not null)');
    $pdo->exec('insert into general_table (setup_step) values (1)');
}

function createWgEasyClientsDatabase(string $path): void
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $pdo->exec(<<<'SQL'
        create table clients_table (
            user_id integer not null,
            interface_id text not null,
            name text not null,
            ipv4_address text not null,
            ipv6_address text not null,
            private_key text not null,
            public_key text not null,
            pre_shared_key text not null,
            allowed_ips text not null,
            server_allowed_ips text not null,
            persistent_keepalive integer not null,
            mtu integer not null,
            dns text not null,
            enabled integer not null
        )
        SQL);
    $pdo->exec("insert into clients_table (user_id, interface_id, name, ipv4_address, ipv6_address, private_key, public_key, pre_shared_key, allowed_ips, server_allowed_ips, persistent_keepalive, mtu, dns, enabled) values (1, 'wg0', 'old', '10.6.0.2', 'fdcc:ad94:bacf:61a4::cafe:2', 'old-private', 'old-public', 'old-psk', '[\"0.0.0.0/0\", \"::/0\"]', '[\"10.6.0.2/32\"]', 25, 1420, '[\"10.6.0.1\"]', 1)");
}

/**
 * @return array<string, mixed>
 */
function readWgEasyStateRow(string $path, string $table): array
{
    $pdo = createWgEasyStateWritableSqliteDatabase($path);
    $row = $pdo->query("select * from {$table} limit 1")->fetch(PDO::FETCH_ASSOC);

    expect($row)->toBeArray();

    return $row;
}

function createWgEasyStateWritableSqliteDatabase(string $path): PDO
{
    $pdo = new PDO("sqlite:{$path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function removeWgEasyStateTempDirectory(?string $path): void
{
    if (! is_string($path) || ! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $entryPath = "{$path}/{$entry}";

        if (is_dir($entryPath)) {
            removeWgEasyStateTempDirectory($entryPath);

            continue;
        }

        chmod($entryPath, 0644);
        unlink($entryPath);
    }

    rmdir($path);
}
