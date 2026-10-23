<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\Transfer\InstanceTransferSource;
use App\Domain\Instances\Transfer\TransferCheckout;
use App\Domain\Instances\Transfer\TransferCleanupResult;
use App\Domain\Instances\Transfer\TransferSourceCapture;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Moves a development Instance between Nodes (docs/reference/instance-transfer.md). The Node agent's
 * `instances.transfer-source` captures, unpacks, and removes checkouts; the Gateway moves the archive with `scp`.
 */
final readonly class RemoteInstanceTransferSource implements InstanceTransferSource
{
    public function __construct(
        private AgentCommandRunner $agents,
        private ProcessRunner $processes,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
    ) {}

    public function capture(Instance $instance, ?string $sqliteSourcePath = null): TransferSourceCapture
    {
        $instance->loadMissing('node');
        $layout = InstanceSourceLayout::from($instance->source_layout);
        $facts = $this->facts($this->run($instance->node, [
            'operation' => 'capture',
            'source' => $instance->checkout_path,
            'layout' => $layout->value,
            'sqlite_source' => $sqliteSourcePath,
        ], 'app-instance-transfer-capture', 'instance.transfer_failed'));

        return new TransferSourceCapture(
            instanceId: $instance->id,
            nodeId: $instance->node_id,
            layout: $layout,
            sourcePath: $instance->checkout_path,
            commonRepositoryPath: $facts['common'] ?? $instance->registration_common_repository_path,
            head: $facts['head'],
            branch: $facts['branch'],
            detached: $facts['detached'],
            archiveIdentity: $facts['archive'],
            refs: $facts['refs'],
        );
    }

    public function materialize(
        TransferSourceCapture $capture,
        Node $destination,
        StoragePath $path,
    ): TransferCheckout {
        $source = Node::query()->findOrFail($capture->nodeId);
        $archive = $this->stageArchive($source, $capture);

        try {
            $this->uploadArchive($destination, $archive, $path, $capture);
        } finally {
            if (is_file($archive)) {
                unlink($archive);
            }
        }

        return new TransferCheckout(
            nodeId: $destination->id,
            path: $path->value,
            layout: InstanceSourceLayout::Checkout,
            head: $capture->head,
            branch: $capture->branch,
            detached: $capture->detached,
        );
    }

    public function discardDestination(Node $node, StoragePath $path): void
    {
        $this->run($node, ['operation' => 'discard', 'destination' => $path->value], 'app-instance-transfer-discard', 'instance.transfer_failed');
    }

    public function cleanupSource(InstanceTransfer $transfer): TransferCleanupResult
    {
        $source = Node::query()->findOrFail($transfer->source_node_id);
        $common = $transfer->common_repository_path;

        try {
            $this->run($source, [
                'operation' => 'cleanup',
                'source' => $transfer->source_path,
                'common' => $common,
            ], 'app-instance-transfer-cleanup', 'instance.transfer_cleanup_incomplete');
        } catch (RuntimeConvergenceException) {
            return new TransferCleanupResult(false, true, ['source-placement']);
        }

        return new TransferCleanupResult(true, true);
    }

    private function stageArchive(Node $source, TransferSourceCapture $capture): string
    {
        $directory = storage_path('app/transfer-staging');
        $temporary = null;

        try {
            new Filesystem()->ensureDirectoryExists($directory, 0700);
            $temporary = tempnam($directory, 'orbit-transfer-');

            // tempnam can fall back to the system temporary directory. Never stage there.
            if (! is_string($temporary) || dirname($temporary) !== realpath($directory) || ! chmod($temporary, 0600)) {
                throw $this->failed();
            }
        } catch (Throwable) {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }

            throw $this->stagingFailed('app-instance-transfer-stage');
        }

        try {
            $this->copyArchive(
                $this->scpFromRemote($source, $capture->archiveIdentity, $temporary),
                'app-instance-transfer-download',
            );
        } catch (Throwable $exception) {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            throw $exception;
        }

        return $temporary;
    }

    private function uploadArchive(
        Node $destination,
        string $archive,
        StoragePath $path,
        TransferSourceCapture $capture,
    ): void {
        $instance = Instance::query()->with('project')->findOrFail($capture->instanceId);
        $environmentDirectory = $instance->source_is_laravel === true
            ? ApplicationDirectory::resolve($path->value, $instance->root ?? $instance->project->root)
            : $path->value;
        $remoteArchive = "/tmp/orbit-transfer-{$capture->instanceId}.tar";
        $this->copyArchive(
            $this->scpToRemote($destination, $archive, $remoteArchive),
            'app-instance-transfer-upload',
        );

        $this->run($destination, [
            'operation' => 'materialize',
            'archive' => $remoteArchive,
            'destination' => $path->value,
            'head' => $capture->head,
            'branch' => $capture->branch,
            'detached' => $capture->detached,
            'environment' => $environmentDirectory.'/.env',
        ], 'app-instance-transfer-materialize', 'instance.transfer_failed');
    }

    /** @param non-empty-list<string> $arguments */
    private function copyArchive(array $arguments, string $step): void
    {
        try {
            $result = $this->processes->run(new ProcessInvocation($arguments, maxOutputBytes: 256));
        } catch (Throwable) {
            throw $this->stagingFailed($step);
        }

        if (! $result->succeeded() || $result->truncated) {
            throw $this->stagingFailed($step, $result->exitCode);
        }
    }

    private function stagingFailed(string $step, ?int $exitCode = null): ResourceOperationException
    {
        Log::warning('Instance transfer archive staging failed.', [
            'step' => $step,
            'exit_code' => $exitCode,
        ]);

        return $this->failed();
    }

    /** @return non-empty-list<string> */
    private function scpFromRemote(Node $node, string $remote, string $local): array
    {
        return $this->scpArguments($node, $this->remotePath($node, $remote), $local);
    }

    /** @return non-empty-list<string> */
    private function scpToRemote(Node $node, string $local, string $remote): array
    {
        return $this->scpArguments($node, $local, $this->remotePath($node, $remote));
    }

    /** @return non-empty-list<string> */
    private function scpArguments(Node $node, string $source, string $target): array
    {
        return [
            'scp',
            '-o',
            'BatchMode=yes',
            '-o',
            'IdentitiesOnly=yes',
            '-o',
            'StrictHostKeyChecking=yes',
            '-i',
            $this->keys->privateKeyPath(),
            '-o',
            'UserKnownHostsFile='.$this->knownHosts->path(),
            $source,
            $target,
        ];
    }

    private function remotePath(Node $node, string $path): string
    {
        $host = $node->wireguard_ip;
        $user = $node->user;

        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw $this->failed();
        }

        $formattedHost = str_contains($host, ':') ? "[{$host}]" : $host;

        return "{$user}@{$formattedHost}:{$path}";
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function run(Node $node, array $input, string $step, string $errorCode): array
    {
        return AgentStep::run($this->agents, $node, 'instances.transfer-source', $input, $step, $errorCode, 'Instance transfer');
    }

    /**
     * @param  array<string, mixed>  $captured
     * @return array{head: string, branch: ?string, detached: bool, archive: string, common: ?string, refs: list<string>}
     */
    private function facts(array $captured): array
    {
        $refs = $captured['refs'] ?? null;

        if (
            ! is_string($captured['head'] ?? null)
            || ! (is_string($captured['branch'] ?? null) || ($captured['branch'] ?? null) === null)
            || ! is_bool($captured['detached'] ?? null)
            || ! is_string($captured['archive'] ?? null)
            || ! (is_string($captured['common'] ?? null) || ($captured['common'] ?? null) === null)
            || ! is_array($refs) || ! array_is_list($refs) || ! array_all($refs, static fn (mixed $ref): bool => is_string($ref))
        ) {
            throw $this->failed();
        }

        return [
            'head' => $captured['head'],
            'branch' => is_string($captured['branch']) ? $captured['branch'] : null,
            'detached' => $captured['detached'],
            'archive' => $captured['archive'],
            'common' => is_string($captured['common']) ? $captured['common'] : null,
            'refs' => array_values(array_filter($refs, is_string(...))),
        ];
    }

    private function failed(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'instance.transfer_failed',
            message: 'The Instance source transfer failed safely.',
            status: 409,
        );
    }
}
