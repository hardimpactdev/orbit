<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\DatabaseClone\InstanceSqliteCloner;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use Throwable;

/**
 * Copies a development SQLite database through the managed account's `instance.copy-sqlite` agent command
 * (docs/reference/instance-cloning.md). On one Node the agent copies it in place. Between Nodes, the source agent
 * exports a snapshot, the Gateway moves it with the protected SQLite transfer, and the target agent installs it once
 * its size and digest match.
 */
final readonly class RemoteInstanceSqliteCloner implements InstanceSqliteCloner
{
    public function __construct(
        private AgentCommandRunner $agents,
        private SqliteSnapshotTransfer $transfer,
    ) {}

    public function copy(Instance $source, string $sourcePath, Instance $target, string $targetPath): void
    {
        $sourceNode = $source->node;
        $targetNode = $target->node;

        if ($sourceNode->id === $targetNode->id) {
            $this->report($this->run($sourceNode, [
                'operation' => 'local',
                'checkout' => $source->checkout_path,
                'source' => $sourcePath,
                'target_checkout' => $target->checkout_path,
                'target' => $targetPath,
            ]));

            return;
        }

        $directory = '/tmp/orbit-sqlite-clone-'.bin2hex(random_bytes(16));
        $incoming = dirname($targetPath).'/.orbit-sqlite-'.bin2hex(random_bytes(16));

        try {
            $snapshot = $this->report($this->run($sourceNode, ['operation' => 'export', 'checkout' => $source->checkout_path, 'source' => $sourcePath, 'directory' => $directory]));
            $this->run($targetNode, ['operation' => 'prepare', 'target_checkout' => $target->checkout_path, 'target' => $targetPath]);

            try {
                $this->transfer->transfer(
                    $this->placement($source),
                    "{$directory}/snapshot.sqlite",
                    $this->placement($target),
                    $incoming,
                    $snapshot['bytes'],
                    $snapshot['sha256'],
                );
            } catch (Throwable $exception) {
                throw $this->failed($exception);
            }

            $this->run($targetNode, [
                'operation' => 'install',
                'target_checkout' => $target->checkout_path,
                'target' => $targetPath,
                'incoming' => $incoming,
                'bytes' => $snapshot['bytes'],
                'sha256' => $snapshot['sha256'],
                'mode' => $snapshot['mode'],
            ]);
        } finally {
            try {
                $this->run($sourceNode, ['operation' => 'cleanup', 'directory' => $directory]);
            } catch (Throwable) {
                // The snapshot directory is private to the managed user; a failed cleanup keeps the clone result.
            }
        }
    }

    public function remove(Instance $owner, string $path): void
    {
        $this->run($owner->node, ['operation' => 'remove', 'checkout' => $owner->checkout_path, 'path' => $path]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Node $node, array $input): array
    {
        try {
            return $this->agents->run($node, 'instance.copy-sqlite', $input);
        } catch (Throwable $exception) {
            throw $this->failed($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{bytes: int, sha256: string, mode: int}
     */
    private function report(array $decoded): array
    {
        if (
            ! is_int($decoded['bytes'] ?? null)
            || ! is_string($decoded['sha256'] ?? null)
            || preg_match('/\A[0-9a-f]{64}\z/D', $decoded['sha256']) !== 1
            || ! is_int($decoded['mode'] ?? null)
        ) {
            throw $this->failed();
        }

        return ['bytes' => $decoded['bytes'], 'sha256' => $decoded['sha256'], 'mode' => $decoded['mode']];
    }

    private function placement(Instance $instance): SqliteSeedPlacement
    {
        return new SqliteSeedPlacement(
            instanceId: $instance->id,
            environment: 'development',
            basePath: $instance->checkout_path,
            executionUser: $instance->node->user,
            node: $instance->node,
        );
    }

    private function failed(?Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.database_clone_failed',
            message: 'The SQLite database could not be copied.',
            status: 502,
            previous: $previous,
        );
    }
}
