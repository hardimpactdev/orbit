<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\RemoteInstanceSqliteSeeder;
use App\Infrastructure\Instances\RemoteInstanceTransferSource;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use Closure;
use Symfony\Component\Process\Process;

/** Runs the real transfer programs locally, redirecting only privileged state and SSH transport. */
final class LocalInstanceTransferTransport implements ProcessRunner, SqliteSnapshotTransfer, SshExecutor
{
    /** @var list<string> */
    public array $stagedPaths = [];

    /** @var list<int> */
    public array $stagedModes = [];

    public ?string $failure = null;

    public ?string $targetBase = null;

    public int $snapshotCopyFailures = 0;

    public bool $lostDiscardResponse = false;

    public ?Closure $onDiscard = null;

    public bool $failSourceCleanup = false;

    public bool $failTargetCleanup = false;

    public bool $lostSourceCleanupResponse = false;

    public bool $lostTargetCleanupResponse = false;

    /** @var list<string> */
    public array $incomingPaths = [];

    /** @var list<string> */
    public array $copiedDigests = [];

    /** @var array<string, string> */
    private array $snapshots = [];

    private static ?ForkedPythonPrograms $python = null;

    public static function prepare(): void
    {
        self::$python = new ForkedPythonPrograms;
    }

    public static function forget(): void
    {
        self::$python?->close();
        self::$python = null;
    }

    public function __construct(private readonly string $sandbox) {}

    public function source(): RemoteInstanceTransferSource
    {
        return new RemoteInstanceTransferSource(
            new DevelopmentSshExecutor($this, $this->keys(), $this->knownHosts()),
            $this,
            $this->keys(),
            $this->knownHosts(),
        );
    }

    public function seeder(): RemoteInstanceSqliteSeeder
    {
        return new RemoteInstanceSqliteSeeder($this, $this, $this->keys(), $this->knownHosts());
    }

    private function keys(): SshKeyProvider
    {
        return new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/unused/transfer-key';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        };
    }

    private function knownHosts(): KnownHostsStore
    {
        return new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/unused/known-hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        };
    }

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $arguments = $invocation->arguments;

        if ($arguments[0] !== 'scp') {
            throw new \LogicException('Only the archive transport is replaced.');
        }

        $target = (string) array_pop($arguments);
        $source = (string) array_pop($arguments);
        $download = str_contains($source, '@');
        $staged = $download ? $target : $source;
        $this->stagedPaths[] = $staged;
        $this->stagedModes[] = fileperms($staged) & 0777;

        if ($this->failure === ($download ? 'download' : 'upload')) {
            file_put_contents($staged, 'partial archive');

            return new CommandResult(23, '', 'TRANSFER_STDERR_SENTINEL', 1, false);
        }

        return new CommandResult(copy($this->localPath($source), $this->localPath($target)) ? 0 : 1, '', '', 1, false);
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $arguments = $command->arguments;
        if (str_contains($command->input ?? '', 'archive=$1'."\n")) {
            $arguments[3] = $this->localPath($arguments[3]);
        }
        $snapshot = null;
        $actualSnapshot = null;
        $role = null;
        $mode = null;

        if ($arguments[0] === 'sudo') {
            $arguments = array_slice($arguments, 3);
            $source = str_contains($arguments[2], 'source.backup(destination');
            $role = $source ? 'source' : 'target';
            $mode = $arguments[3];
            if ($mode === 'cleanup' && ($source ? $this->failSourceCleanup : $this->failTargetCleanup)) {
                return new CommandResult(1, '', 'Injected seed cleanup failure.', 1, false);
            }
            $stateIndex = $source ? 9 : 8;
            $arguments[$stateIndex] = $this->sandbox.'/state/'.basename($arguments[$stateIndex]);

            if ($source) {
                $snapshot = $arguments[10];
                $actualSnapshot = $this->sandbox.'/snapshots/'.basename($snapshot);
                $this->snapshots[$snapshot] = $actualSnapshot;
                $arguments[10] = $actualSnapshot;
            } else {
                $this->targetBase = $arguments[4];
            }
        }

        if ($command->output !== null || $command->cancelled !== null || $command->protectedInput !== null) {
            throw new \LogicException('Finite transfer proofs do not replace streaming, cancellation, or protected-input transports.');
        }
        if (array_slice($arguments, 0, 2) === ['python3', '-c']) {
            $python = self::$python ?? throw new \LogicException('Prepare the transfer fixture host in beforeAll.');
            $result = $python->runCommand($arguments[2], array_slice($arguments, 3), $command->input ?? '', timeoutSeconds: $command->timeout);
        } else {
            $input = $command->input;
            if ($arguments[0] === 'bash' && $input !== null) {
                $input = BufferedBashProgram::stdinPrelude($this->sandbox).$input;
            }
            $process = new Process($arguments, input: $input, timeout: $command->timeout ?? 60);
            $process->run();
            $result = new CommandResult((int) $process->getExitCode(), $process->getOutput(), $process->getErrorOutput(), 1, false);
        }
        $stdout = $result->stdout;

        if ($snapshot !== null && $actualSnapshot !== null) {
            $stdout = str_replace($actualSnapshot, $snapshot, $stdout);
        }

        if ($role === 'target' && $mode === 'prepare' && str_starts_with($stdout, "READY\t")) {
            $this->incomingPaths[] = trim(substr($stdout, 6));
        }
        if ($result->exitCode === 0 && str_contains($command->input ?? '', 'destination=$1')) {
            ($this->onDiscard)?->__invoke();
        }
        $lostCleanupResponse = $mode === 'cleanup' && ($role === 'source' ? $this->lostSourceCleanupResponse : $this->lostTargetCleanupResponse);
        $lostDiscardResponse = $this->lostDiscardResponse && str_contains($command->input ?? '', 'destination=$1');
        if ($result->exitCode === 0 && ($lostCleanupResponse || $lostDiscardResponse)) {
            if ($lostDiscardResponse) {
                $this->lostDiscardResponse = false;
            } elseif ($role === 'source') {
                $this->lostSourceCleanupResponse = false;
            } else {
                $this->lostTargetCleanupResponse = false;
            }

            return new CommandResult(1, '', 'Injected lost response after successful cleanup.', 1, false);
        }

        return new CommandResult($result->exitCode, $stdout, $result->stderr, $result->durationMs, $result->truncated);
    }

    private function localPath(string $path): string
    {
        $path = preg_replace('/\A[^@\/]+@[^:]+:/', '', $path) ?? $path;

        return preg_match('/\A\/tmp\/orbit-transfer-\d+\.tar\z/', $path) === 1
            ? $this->sandbox.'/remote-archive.tar'
            : $path;
    }

    public function transfer(
        SqliteSeedPlacement $source,
        string $sourcePath,
        SqliteSeedPlacement $target,
        string $targetPath,
        int $expectedBytes,
        string $expectedDigest,
    ): void {
        $snapshot = $this->snapshots[$sourcePath];
        $this->copiedDigests[] = $expectedDigest;

        if (filesize($snapshot) !== $expectedBytes || hash_file('sha256', $snapshot) !== $expectedDigest || ! copy($snapshot, $targetPath)) {
            throw new \RuntimeException('The local SQLite snapshot copy failed.');
        }

        if ($this->snapshotCopyFailures > 0) {
            $this->snapshotCopyFailures--;
            throw new \RuntimeException('Injected SQLite copy failure after writing the incoming payload.');
        }
    }

    /** @return list<string> */
    public function snapshotPaths(): array
    {
        return array_values($this->snapshots);
    }
}
