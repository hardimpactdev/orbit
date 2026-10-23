<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Instances\RemoteInstanceSqliteSeeder;
use App\Infrastructure\Instances\RemoteInstanceTransferSource;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;

/** Runs the real transfer agent commands locally, and replaces only the `scp` transport and the SQLite move. */
final class LocalInstanceTransferTransport implements ProcessRunner, SqliteSnapshotTransfer
{
    /** @var list<string> */
    public array $stagedPaths = [];

    /** @var list<int> */
    public array $stagedModes = [];

    public ?string $failure = null;

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

    public function __construct(private readonly string $sandbox) {}

    /** The real transfer source, whose agent commands run as the current user. */
    public function source(): RemoteInstanceTransferSource
    {
        $transport = $this;
        $agents = LocalAgentBinary::localRunner($this->sandbox.'/agent');

        return new RemoteInstanceTransferSource(new class($transport, $agents) implements AgentCommandRunner
        {
            public function __construct(private readonly LocalInstanceTransferTransport $transport, private readonly AgentCommandRunner $agents) {}

            public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array
            {
                return $this->transport->sourceCommand($input, fn (array $input): array => $this->agents->run($node, $command, $input, $deadlineSeconds));
            }

            public function install(Node $node): bool
            {
                return $this->agents->install($node);
            }
        }, $this, $this->keys(), $this->knownHosts());
    }

    /**
     * Runs one transfer source command against the local archive, and applies an injected lost discard response.
     *
     * @param  array<string, mixed>  $input
     * @param  Closure(array<string, mixed>): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    public function sourceCommand(array $input, Closure $run): array
    {
        if (($input['operation'] ?? null) === 'materialize') {
            $input['archive'] = $this->localPath((string) $input['archive']);
        }
        $output = $run($input);
        if (($input['operation'] ?? null) === 'discard') {
            ($this->onDiscard)?->__invoke();
            if ($this->lostDiscardResponse) {
                $this->lostDiscardResponse = false;

                throw new \RuntimeException('Injected lost response after successful cleanup.');
            }
        }

        return $output;
    }

    /** The real seeder, whose agent commands run as root through `sudo`, so only a privileged test host runs it. */
    public function seeder(): RemoteInstanceSqliteSeeder
    {
        $transport = $this;
        $agents = LocalAgentBinary::localRunner($this->sandbox.'/agent');

        return new RemoteInstanceSqliteSeeder(new class($transport, $agents) implements AgentCommandRunner
        {
            public function __construct(private readonly LocalInstanceTransferTransport $transport, private readonly AgentCommandRunner $agents) {}

            public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array
            {
                return $this->transport->seedCommand($command, $input, fn (): array => $this->agents->run($node, $command, $input, $deadlineSeconds));
            }

            public function install(Node $node): bool
            {
                return $this->agents->install($node);
            }
        }, $this);
    }

    /**
     * Runs one seed command and applies the injected cleanup failures and lost responses.
     *
     * @param  array<string, mixed>  $input
     * @param  Closure(): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    public function seedCommand(string $command, array $input, Closure $run): array
    {
        $source = $command === 'instances.prepare-sqlite-seed';
        $cleanup = ($input['mode'] ?? null) === 'cleanup';
        if ($cleanup && ($source ? $this->failSourceCleanup : $this->failTargetCleanup)) {
            throw new \RuntimeException('Injected seed cleanup failure.');
        }
        $output = $run();
        if ($source && is_string($output['snapshot'] ?? null)) {
            $this->snapshots[$output['snapshot']] = $output['snapshot'];
        }
        if (! $source && ($output['state'] ?? null) === 'ready' && is_string($output['incoming'] ?? null)) {
            $this->incomingPaths[] = $output['incoming'];
        }
        $lost = $cleanup && ($source ? $this->lostSourceCleanupResponse : $this->lostTargetCleanupResponse);
        if ($lost) {
            $source ? $this->lostSourceCleanupResponse = false : $this->lostTargetCleanupResponse = false;

            throw new \RuntimeException('Injected lost response after successful cleanup.');
        }

        return $output;
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
