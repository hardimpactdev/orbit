<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Instances\Sqlite\SqliteSeedResult;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use SensitiveParameter;
use Throwable;

/**
 * Seeds a SQLite database through the Node agents' `instances.prepare-sqlite-seed` and
 * `instances.install-sqlite-seed` (docs/reference/instance-cloning.md). The source agent takes an integrity-checked
 * snapshot, the Gateway moves it with the protected transfer, and the target agent installs it as a new file. Each
 * agent records its progress, so a retry resumes or cleans up what an earlier run left.
 */
final readonly class RemoteInstanceSqliteSeeder implements InstanceSqliteSeeder
{
    private const string StateRoot = '/var/lib/orbit/app-instance-sqlite-seeds';

    public function __construct(
        private AgentCommandRunner $agents,
        private SqliteSnapshotTransfer $transfer,
    ) {}

    public function seed(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        #[SensitiveParameter]
        string $sourcePath,
    ): SqliteSeedResult {
        $this->assertRequest($source, $target, $sourcePath);
        [$operation, $sourceState, $targetState, $snapshotPath, $relativePath] = $this->seedIdentity($source, $target, $sourcePath);
        $sourceInput = $this->sourceInput($source, $sourcePath, $operation, $sourceState, $snapshotPath);
        $targetInput = $this->targetInput($target, $operation, $targetState, $relativePath);

        try {
            $snapshot = $this->agents->run($source->node, 'instances.prepare-sqlite-seed', [...$sourceInput, 'mode' => 'prepare']);
        } catch (Throwable) {
            $this->failPreflight();
        }

        if (
            ! is_int($snapshot['bytes'] ?? null) || $snapshot['bytes'] < 1
            || ! is_string($snapshot['sha256'] ?? null) || preg_match('/\A[0-9a-f]{64}\z/D', $snapshot['sha256']) !== 1
            || ($snapshot['snapshot'] ?? null) !== $snapshotPath
        ) {
            $this->failPreflight();
        }

        $targetInput = [...$targetInput, 'expected_size' => $snapshot['bytes'], 'expected_digest' => $snapshot['sha256']];

        try {
            $prepared = $this->agents->run($target->node, 'instances.install-sqlite-seed', [...$targetInput, 'mode' => 'prepare']);
        } catch (AgentCommandRefused $refusal) {
            if ($refusal->errorCode === 'instances.sqlite_seed_refused') {
                $this->cleanupSource($source, $sourceInput);
            }
            $this->failPreflight();
        } catch (Throwable) {
            $this->failPreflight();
        }

        $state = $prepared['state'] ?? null;

        if ($state === 'complete') {
            return $this->cleanupResult($source, $sourceInput, false);
        }

        if ($state === 'ready') {
            $incoming = $prepared['incoming'] ?? null;

            if (! is_string($incoming) || preg_match('/\A\/tmp\/orbit-sqlite-[A-Za-z0-9._-]+\z/D', $incoming) !== 1) {
                $this->failPreflight();
            }

            try {
                $this->transfer->transfer($source, $snapshotPath, $target, $incoming, $snapshot['bytes'], $snapshot['sha256']);
            } catch (Throwable) {
                $this->failTransfer();
            }
        } elseif ($state !== 'resume') {
            $this->failPreflight();
        }

        try {
            $installed = $this->agents->run($target->node, 'instances.install-sqlite-seed', [...$targetInput, 'mode' => 'install']);
        } catch (AgentCommandRefused $refusal) {
            if ($refusal->errorCode === 'instances.sqlite_seed_refused') {
                $this->cleanupSource($source, $sourceInput);
                $this->failInstall();
            }
            if ($refusal->errorCode === 'instances.sqlite_seed_failed') {
                $this->failInstall();
            }

            return SqliteSeedResult::unconfirmed();
        } catch (Throwable) {
            return SqliteSeedResult::unconfirmed();
        }

        return match ($installed['state'] ?? null) {
            'changed' => $this->cleanupResult($source, $sourceInput, true),
            'complete' => $this->cleanupResult($source, $sourceInput, false),
            default => SqliteSeedResult::unconfirmed(),
        };
    }

    public function abandon(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        #[SensitiveParameter]
        string $sourcePath,
    ): bool {
        try {
            $this->assertRequest($source, $target, $sourcePath);
        } catch (Throwable) {
            return false;
        }

        [$operation, $sourceState, $targetState, $snapshotPath, $relativePath] = $this->seedIdentity($source, $target, $sourcePath);
        $sourceCleaned = $this->cleanupSource($source, $this->sourceInput($source, $sourcePath, $operation, $sourceState, $snapshotPath));

        try {
            $result = $this->agents->run($target->node, 'instances.install-sqlite-seed', [
                ...$this->targetInput($target, $operation, $targetState, $relativePath),
                'mode' => 'cleanup',
                'expected_size' => 0,
                'expected_digest' => '',
            ]);
        } catch (Throwable) {
            return false;
        }

        return $sourceCleaned && ($result['state'] ?? null) === 'cleaned';
    }

    /** @return array{string, string, string, string, string} */
    private function seedIdentity(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath): array
    {
        $operation = hash('sha256', implode("\0", [
            'sqlite-seed-v1',
            (string) $source->instanceId,
            $source->environment,
            $source->basePath,
            $source->executionUser,
            (string) $target->instanceId,
            $target->basePath,
            $target->executionUser,
            $sourcePath,
            ...($target->environment === 'development' ? [(string) $source->node->id, (string) $target->node->id, $target->operationId ?? ''] : []),
        ]));
        $sourceState = self::StateRoot."/source-{$source->instanceId}-target-{$target->instanceId}";
        $targetState = self::StateRoot."/target-{$target->instanceId}";
        $relativePath = 'database.sqlite';

        if ($target->environment === 'development') {
            $relativePath = substr($sourcePath, strlen($source->basePath) + 1);
            $sourceState .= "-{$operation}";
            $targetState .= "-{$operation}";
        }

        return [$operation, $sourceState, $targetState, "/tmp/orbit-sqlite-{$operation}.sqlite", $relativePath];
    }

    /** @param  array<string, mixed>  $sourceInput */
    private function cleanupResult(SqliteSeedPlacement $source, array $sourceInput, bool $changed): SqliteSeedResult
    {
        if (! $this->cleanupSource($source, $sourceInput)) {
            return SqliteSeedResult::unconfirmed();
        }

        return $changed ? SqliteSeedResult::changed() : SqliteSeedResult::unchanged();
    }

    /** @param  array<string, mixed>  $sourceInput */
    private function cleanupSource(SqliteSeedPlacement $source, array $sourceInput): bool
    {
        try {
            $this->agents->run($source->node, 'instances.prepare-sqlite-seed', [...$sourceInput, 'mode' => 'cleanup']);
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /** @return array<string, string> */
    private function sourceInput(SqliteSeedPlacement $source, string $sourcePath, string $operation, string $stateDirectory, string $snapshotPath): array
    {
        return [
            'base' => $source->basePath,
            'source_path' => $sourcePath,
            'source_user' => $source->executionUser,
            'transport_user' => (string) $source->node->user,
            'operation' => $operation,
            'state_dir' => $stateDirectory,
            'snapshot_path' => $snapshotPath,
        ];
    }

    /** @return array<string, string> */
    private function targetInput(SqliteSeedPlacement $target, string $operation, string $stateDirectory, string $relativePath): array
    {
        return [
            'base' => $target->basePath,
            'target_user' => $target->executionUser,
            'transport_user' => (string) $target->node->user,
            'operation' => $operation,
            'state_dir' => $stateDirectory,
            'environment' => $target->environment,
            'relative_path' => $relativePath,
        ];
    }

    private function assertRequest(
        SqliteSeedPlacement $source,
        SqliteSeedPlacement $target,
        string $sourcePath,
    ): void {
        $isTransfer = $source->instanceId === $target->instanceId
            && $source->environment === 'development'
            && $target->environment === 'development'
            && $source->node->id !== $target->node->id;

        if (
            $source->instanceId <= 0
            || $target->instanceId <= 0
            || ($source->instanceId === $target->instanceId && ! $isTransfer)
            || ! in_array($source->environment, ['development', 'production'], true)
            || ($target->environment !== 'production' && ! $isTransfer)
            || ! $this->safeAbsolutePath($source->basePath)
            || ! $this->safeAbsolutePath($target->basePath)
            || ! $this->safeAbsolutePath($sourcePath)
            || ! str_starts_with($sourcePath, $source->basePath.'/')
            || ! $this->safeUser($source->executionUser)
            || ! $this->safeUser($target->executionUser)
            || ($target->environment === 'production' && $target->basePath !== "/home/{$target->executionUser}")
            || ! $this->availableNode($source)
            || ! $this->availableNode($target)
        ) {
            $this->failPreflight();
        }
    }

    private function safeAbsolutePath(string $path): bool
    {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || str_contains($path, "\n") || str_contains($path, "\r")) {
            return false;
        }

        $segments = array_slice(explode('/', $path), 1);

        return $segments !== [] && array_all(
            $segments,
            static fn (string $segment): bool => ! in_array($segment, ['', '.', '..'], true),
        );
    }

    private function safeUser(string $user): bool
    {
        return preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) === 1;
    }

    private function availableNode(SqliteSeedPlacement $placement): bool
    {
        return
            is_string($placement->node->wireguard_ip)
            && filter_var($placement->node->wireguard_ip, FILTER_VALIDATE_IP) !== false
            && $this->safeUser($placement->node->user);
    }

    private function failPreflight(): never
    {
        throw new ResourceOperationException(
            errorCode: 'sqlite.seed_preflight_failed',
            message: 'The recorded SQLite seed source or target is unavailable.',
            status: 409,
        );
    }

    private function failTransfer(): never
    {
        throw new ResourceOperationException(
            errorCode: 'sqlite.seed_transfer_failed',
            message: 'The SQLite snapshot transfer failed safely.',
            status: 409,
        );
    }

    private function failInstall(): never
    {
        throw new ResourceOperationException(
            errorCode: 'sqlite.seed_failed',
            message: 'The target SQLite seed installation failed safely.',
            status: 409,
        );
    }
}
