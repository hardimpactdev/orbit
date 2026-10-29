<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\Instances\ProtectedSqliteSnapshotTransfer;
use App\Infrastructure\Instances\RemoteInstanceSqliteSeeder;
use App\Infrastructure\Instances\RemoteInstanceTransferSource;
use App\Models\Node;
use Closure;
use RuntimeException;

/** Command responses for transfer recovery tests. Rust tests own archive and SQLite filesystem behavior. */
final class FakeInstanceTransferAgents
{
    public FakeAgentCommandRunner $agents;

    public string $sourceBytes = 'original snapshot';

    public ?string $snapshot = null;

    public ?string $received = null;

    public ?string $installed = null;

    public bool $destinationExists = false;

    public int $snapshotCopyFailures = 0;

    public bool $lostDiscardResponse = false;

    public ?Closure $onDiscard = null;

    public bool $failSourceCleanup = false;

    public bool $failTargetCleanup = false;

    public bool $lostSourceCleanupResponse = false;

    public bool $lostTargetCleanupResponse = false;

    /** @var list<string> */
    public array $copiedDigests = [];

    public function __construct()
    {
        $this->agents = new FakeAgentCommandRunner;
        $this->agents->answer('instance.transfer-source', function (array $input): array {
            if ($input['operation'] === 'capture') {
                return ['head' => str_repeat('a', 40), 'branch' => 'main', 'detached' => false,
                    'archive' => '/tmp/orbit-transfer-source.tar', 'common' => null, 'refs' => ['main']];
            }
            if ($input['operation'] === 'discard') {
                $this->destinationExists = false;
                $this->installed = null;
                ($this->onDiscard)?->__invoke();
                if ($this->lostDiscardResponse) {
                    $this->lostDiscardResponse = false;
                    throw new RuntimeException('Injected lost discard response.');
                }
            }

            return [];
        })->answer('instance.read-transfer-archive', static function (array $input, Node $node, ?AgentBinaryOutput $output): array {
            $output?->write('archive');

            return ['bytes' => 7, 'sha256' => hash('sha256', 'archive')];
        })->answer('instance.materialize-transfer', function (): array {
            $this->destinationExists = true;

            return [];
        })->answer('instance.prepare-sqlite-seed', function (array $input): array {
            if ($input['mode'] === 'cleanup') {
                if ($this->failSourceCleanup) {
                    throw new RuntimeException('Injected source cleanup failure.');
                }
                $this->snapshot = null;
                if ($this->lostSourceCleanupResponse) {
                    $this->lostSourceCleanupResponse = false;
                    throw new RuntimeException('Injected lost source cleanup response.');
                }

                return [];
            }
            $this->snapshot ??= $this->sourceBytes;

            return ['bytes' => strlen($this->snapshot), 'sha256' => hash('sha256', $this->snapshot), 'snapshot' => $input['snapshot_path']];
        })->answer('instance.install-sqlite-seed', function (array $input): array {
            if ($input['mode'] === 'cleanup') {
                if ($this->failTargetCleanup) {
                    throw new RuntimeException('Injected target cleanup failure.');
                }
                $this->received = null;
                if ($this->lostTargetCleanupResponse) {
                    $this->lostTargetCleanupResponse = false;
                    throw new RuntimeException('Injected lost target cleanup response.');
                }

                return ['state' => 'cleaned'];
            }
            if ($input['mode'] === 'prepare') {
                return ['state' => 'ready', 'incoming' => '/tmp/orbit-sqlite-1234567890abcdef-1234567890abcdef'];
            }
            $this->installed = $this->received;
            $this->received = null;

            return ['state' => 'changed'];
        })->answer('instance.read-sqlite-snapshot', function (array $input, Node $node, ?AgentBinaryOutput $output): array {
            $snapshot = $this->snapshot ?? throw new RuntimeException('No prepared snapshot.');
            $output?->write($snapshot);

            return ['bytes' => strlen($snapshot), 'sha256' => hash('sha256', $snapshot)];
        })->answer('instance.receive-sqlite-snapshot', function (array $input, Node $node, ?AgentBinaryOutput $output, ?AgentBinaryInput $binary): array {
            $body = $binary?->envelope('') ?? throw new RuntimeException('No snapshot body.');
            $this->received = ProcessInput::contents($body);
            $this->copiedDigests[] = $input['sha256'];
            if ($this->snapshotCopyFailures > 0) {
                $this->snapshotCopyFailures--;
                throw new RuntimeException('Injected snapshot transfer failure.');
            }

            return ['bytes' => strlen($this->received), 'sha256' => hash('sha256', $this->received)];
        });
    }

    public function source(): RemoteInstanceTransferSource
    {
        return new RemoteInstanceTransferSource($this->agents, app(AgentContract::class));
    }

    public function seeder(): RemoteInstanceSqliteSeeder
    {
        return new RemoteInstanceSqliteSeeder($this->agents, new ProtectedSqliteSnapshotTransfer($this->agents));
    }

    public function captures(): int
    {
        return count(array_filter($this->agents->calls, static fn (array $call): bool => $call['command'] === 'instance.transfer-source' && $call['input']['operation'] === 'capture'));
    }
}
