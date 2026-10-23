<?php

declare(strict_types=1);

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Infrastructure\Instances\ProtectedSqliteSnapshotTransfer;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;

it('streams the protected snapshot through bounded scp invocations and removes the local staging file', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    $targetPath = "{$sandbox}/target/incoming.sqlite";
    sqlite_seed_create_database($sourcePath, rows: 25);
    $bytes = filesize($sourcePath);
    $digest = hash_file('sha256', $sourcePath);

    try {
        $processes = new SqliteSeedScpProcessRunner;
        $transfer = new ProtectedSqliteSnapshotTransfer(
            $processes,
            sqlite_seed_key_provider(),
            sqlite_seed_known_hosts(),
        );

        $transfer->transfer(
            sqlite_seed_source_placement($sandbox),
            $sourcePath,
            sqlite_seed_target_placement(),
            $targetPath,
            $bytes,
            $digest,
        );

        expect(hash_file('sha256', $targetPath))
            ->toBe($digest)
            ->and(sqlite_seed_integrity($targetPath))
            ->toBe('ok');
        expect($processes->invocations)
            ->toHaveCount(2);

        foreach ($processes->invocations as $invocation) {
            expect($invocation->arguments[0])
                ->toBe('scp')
                ->and($invocation->maxOutputBytes)
                ->toBe(256)
                ->and($invocation->input)
                ->toBeNull()
                ->and($invocation->protectedInput)
                ->toBeNull();
        }

        $localStagingPath = array_last($processes->invocations[0]->arguments);

        expect(file_exists($localStagingPath))
            ->toBeFalse()
            ->and(json_encode($processes->invocations, JSON_THROW_ON_ERROR))
            ->not->toContain('seed-row-25');
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
});

it('redacts protected transfer failures and removes their local staging file', function (): void {
    $sandbox = sqlite_seed_sandbox();
    $sourcePath = "{$sandbox}/source/database.sqlite";
    sqlite_seed_create_database($sourcePath);
    $sensitive = 'raw-database-diagnostic-sentinel';

    try {
        $processes = new SqliteSeedScpProcessRunner($sensitive);
        $transfer = new ProtectedSqliteSnapshotTransfer(
            $processes,
            sqlite_seed_key_provider(),
            sqlite_seed_known_hosts(),
        );

        try {
            $transfer->transfer(
                sqlite_seed_source_placement($sandbox),
                $sourcePath,
                sqlite_seed_target_placement(),
                "{$sandbox}/target/incoming.sqlite",
                filesize($sourcePath),
                hash_file('sha256', $sourcePath),
            );
            $this->fail('The failed protected transfer unexpectedly passed.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())
                ->toBe('The protected SQLite snapshot transfer failed safely.')
                ->not->toContain($sensitive)
                ->and($exception->getPrevious())
                ->toBeNull();
        }

        $localStagingPath = array_last($processes->invocations[0]->arguments);

        expect(file_exists($localStagingPath))
            ->toBeFalse();
    } finally {
        sqlite_seed_remove_directory($sandbox);
    }
});

function sqlite_seed_sandbox(): string
{
    $sandbox = sys_get_temp_dir().'/orbit-sqlite-transfer-'.bin2hex(random_bytes(8));
    mkdir("{$sandbox}/source", 0o700, true);
    mkdir("{$sandbox}/target", 0o700);

    return $sandbox;
}

function sqlite_seed_remove_directory(string $directory): void
{
    new Filesystem()->deleteDirectory($directory);
}

function sqlite_seed_create_database(string $path, int $rows = 5): void
{
    $database = new PDO("sqlite:{$path}");
    $database->exec('CREATE TABLE seed_events (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');
    $insert = $database->prepare('INSERT INTO seed_events (value) VALUES (?)');

    for ($index = 1; $index <= $rows; $index++) {
        $insert->execute(["seed-row-{$index}"]);
    }
}

function sqlite_seed_integrity(string $path): string
{
    return (string) new PDO("sqlite:{$path}")->query('PRAGMA integrity_check')->fetchColumn();
}

function sqlite_seed_placement(string $environment, string $base): SqliteSeedPlacement
{
    $node = new Node;
    $node->forceFill(['wireguard_ip' => '127.0.0.1', 'user' => 'orbit']);

    return new SqliteSeedPlacement(instanceId: 11, environment: $environment, basePath: $base, executionUser: 'orbit', node: $node);
}

function sqlite_seed_source_placement(string $sandbox): SqliteSeedPlacement
{
    return sqlite_seed_placement('development', "{$sandbox}/source");
}

function sqlite_seed_target_placement(): SqliteSeedPlacement
{
    return sqlite_seed_placement('production', '/home/orbit');
}

function sqlite_seed_key_provider(): SshKeyProvider
{
    return new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-seed-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    };
}

function sqlite_seed_known_hosts(): KnownHostsStore
{
    return new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-seed-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };
}

final class SqliteSeedScpProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public function __construct(
        private readonly ?string $failureDiagnostic = null,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        if ($this->failureDiagnostic !== null) {
            return new CommandResult(1, $this->failureDiagnostic, $this->failureDiagnostic, 1, false);
        }

        $source = $invocation->arguments[array_key_last($invocation->arguments) - 1];
        $target = array_last($invocation->arguments);
        $sourcePath = $this->localPath($source);
        $targetPath = $this->localPath($target);

        if (! copy($sourcePath, $targetPath)) {
            return new CommandResult(1, '', '', 1, false);
        }

        return new CommandResult(0, '', '', 1, false);
    }

    private function localPath(string $path): string
    {
        if (! str_contains($path, '@')) {
            return $path;
        }

        if (preg_match('/\A[^@]+@(?:\[[^]]+\]|[^:]+):(.+)\z/D', $path, $matches) !== 1) {
            throw new RuntimeException('The fake received an invalid remote path.');
        }

        return $matches[1];
    }
}
