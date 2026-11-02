<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\Instances\Sqlite\SqliteSeedPlacement;
use App\Domain\Shared\ResourceOperationException;

/** Records safe placement descriptors. Swamp owns all snapshot and cleanup steps. */
final readonly class PrepareSqliteIntentAction
{
    /** @return array<string, mixed> */
    public function seed(SqliteSeedPlacement $source, SqliteSeedPlacement $target, string $sourcePath): array
    {
        $transfer = $source->instanceId === $target->instanceId && $source->environment === 'development'
            && $target->environment === 'development' && $source->node->id !== $target->node->id;
        $safeUser = static fn (string $user): bool => preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $user) === 1;
        if ($source->instanceId < 1 || $target->instanceId < 1 || ($source->instanceId === $target->instanceId && ! $transfer)
            || ! in_array($source->environment, ['development', 'production'], true) || ($target->environment !== 'production' && ! $transfer)
            || ($transfer && ($target->operationId === null || trim($target->operationId) === '' || strlen($target->operationId) > 200
                || ($source->operationId !== null && $source->operationId !== $target->operationId)))
            || ! $this->path($source->basePath) || ! $this->path($target->basePath) || ! $this->path($sourcePath)
            || ! str_starts_with($sourcePath, $source->basePath.'/')
            || ! $safeUser($source->executionUser) || ! $safeUser($target->executionUser)
            || ! $safeUser($source->node->user) || ! $safeUser($target->node->user)
            || ($target->environment === 'production' && $target->basePath !== '/home/'.$target->executionUser)
            || filter_var($source->node->wireguard_ip, FILTER_VALIDATE_IP) === false
            || filter_var($target->node->wireguard_ip, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('sqlite.seed_preflight_failed', 'The recorded SQLite source or target is unavailable.', 409);
        }
        $identity = hash('sha256', implode("\0", ['sqlite-seed-v1', (string) $source->instanceId, $source->environment, $source->basePath,
            $source->executionUser, (string) $target->instanceId, $target->basePath, $target->executionUser, $sourcePath,
            ...($transfer ? [(string) $source->node->id, (string) $target->node->id, $target->operationId ?? ''] : [])]));
        $root = '/var/lib/orbit/app-instance-sqlite-seeds';
        $suffix = $transfer ? '-'.$identity : '';

        return [
            'owner_instance_id' => $target->instanceId,
            'source_instance_id' => $source->instanceId,
            'participant_node_ids' => array_values(array_unique([$source->node->id, $target->node->id])),
            'source' => [
                'variant' => 'seed', 'node_id' => $source->node->id,
                'target_instance_id' => $target->instanceId, 'base' => $source->basePath,
                'source_path' => $sourcePath, 'source_user' => $source->executionUser, 'transport_user' => $source->node->user,
                'operation' => $identity, 'state_dir' => $root.'/source-'.$source->instanceId.'-target-'.$target->instanceId.$suffix,
                'snapshot_path' => '/tmp/orbit-sqlite-'.$identity.'.sqlite',
            ],
            'target' => [
                'node_id' => $target->node->id,
                'target_instance_id' => $target->instanceId, 'base' => $target->basePath,
                'target_user' => $target->executionUser, 'transport_user' => $target->node->user,
                'operation' => $identity, 'state_dir' => $root.'/target-'.$target->instanceId.$suffix,
                'environment' => $target->environment,
                'relative_path' => $transfer ? substr($sourcePath, strlen($source->basePath) + 1) : 'database.sqlite',
            ],
        ];
    }

    private function path(string $path): bool
    {
        return strlen($path) > 1 && str_starts_with($path, '/') && ! str_ends_with($path, '/')
            && ! preg_match('#[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//#', $path);
    }
}
