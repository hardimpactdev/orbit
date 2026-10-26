<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Domain\Tools\VpEnvironment;
use App\Domain\Tools\VpProjectRequirements;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class PrepareVpEnvironmentAction
{
    public function __construct(
        private VpToolManager $vp,
        private AgentCommandRunner $agents,
        private ToolManagerScopeLock $lock,
    ) {}

    public function prepare(Instance $instance, string $path, string $name, string $command): ?VpEnvironment
    {
        if ($name !== 'npm-build' && preg_match('/(?:^|[\s;&|\/])(vp|node|npm|npx|pnpm|yarn|bun|vite|corepack)(?:\s|$)/', $command) !== 1) {
            return null;
        }
        $previous = $this->checkoutReferences($instance->getAttribute('vp_environment'), $instance->checkout_path);
        try {
            $user = $instance->placedOnAppProd() ? $instance->production_user : null;
            if ($instance->placedOnAppProd() && (! is_string($user) || preg_match('/\Aorbit-app-[0-9]+\z/D', $user) !== 1)) {
                throw new InvalidArgumentException('Invalid Instance account.');
            }
            try {
                $result = $this->agents->run($instance->node, 'tool.read-vp-project', ['path' => $path, 'user' => $user ?? $instance->node->user]);
            } catch (AgentCommandRefused $exception) {
                if ($exception->errorCode !== 'tool.project_invalid') {
                    throw $exception;
                }
                throw new InvalidArgumentException('Cannot read Project requirements.', previous: $exception);
            }
            $environment = (new VpProjectRequirements)->resolve($result, '/unresolved');
            if ($environment === null) {
                // Removing vp from a candidate does not retire the selected or retained releases.
                if ($previous !== null) {
                    $this->referenceTransaction($instance, fn (): bool => $instance->update(['vp_environment' => $previous]));
                }

                return null;
            }
            $store = dirname($this->vp->existingBinary($instance->node), 2);
            $environment = VpEnvironment::fromArray([...$environment->record('checking'), 'store' => $store]);
            $this->vp->ensureVersion($instance->node, $environment->vpVersion);
            $identity = $environment->record('checking');
            unset($identity['status']);
            $previous = $this->filterReferences($previous, static function (array $record) use ($path, $identity): bool {
                foreach ($identity as $key => $value) {
                    if (($record[$key] ?? null) !== $value) {
                        return true;
                    }
                }

                return ($record['checkout_path'] ?? null) !== $path;
            });
            // Register intent before observing, retaining every checkout until release cleanup retires it.
            $intent = [...$environment->record('checking'), 'checkout_path' => $path, 'previous' => $previous];
            $this->referenceTransaction($instance, fn (): bool => $instance->update(['vp_environment' => $intent]));
            if ($this->vp->environmentInstalled($instance->node, $environment)) {
                $instance->update(['vp_environment' => [...$intent, 'status' => 'active']]);

                return $environment;
            }
            $this->blocking($instance, function () use ($instance, $environment, $result, $intent): void {
                $instance->update(['vp_environment' => [...$intent, 'status' => 'installing']]);
                if (! $this->vp->environmentInstalled($instance->node, $environment)) {
                    $npmrc = $result['npmrc'] ?? '';
                    $this->vp->installEnvironment($instance->node, $environment, is_string($npmrc) ? $npmrc : '');
                }
                if (! $this->vp->environmentInstalled($instance->node, $environment)) {
                    throw new ResourceOperationException('deployment.vp_store_failed', 'The required vp runtime is missing after owner-only installation.', 502);
                }
                $instance->update(['vp_environment' => [...$intent, 'status' => 'active']]);
                $this->prune($instance, $environment->store);
            });

            return $environment;
        } catch (Throwable $exception) {
            $code = $exception instanceof InvalidArgumentException ? 'deployment.vp_requirements_invalid' : 'deployment.vp_store_failed';
            $record = $instance->getAttribute('vp_environment');
            $instance->update(['vp_environment' => [...(is_array($record) ? $record : []), 'status' => 'failed', 'error_code' => $code]]);
            if ($exception instanceof ResourceOperationException) {
                throw $exception;
            }
            throw new ResourceOperationException($code, $code === 'deployment.vp_requirements_invalid'
                ? 'Pin exact Node and package-manager versions and commit a compatible vite-plus lockfile before deploying.'
                : 'The vp prerequisite failed before build. Check the machine recipe version, store ownership, lock timeout, checksum, and Project registry access.', 502, $exception);
        }
    }

    /** Drop references only for releases actually retired by production release cleanup.
     *
     * @param  list<string>  $paths
     */
    public function releaseRemoved(Instance $instance, array $paths): void
    {
        if ($paths === [] || $instance->getAttribute('vp_environment') === null) {
            return;
        }
        $this->blocking($instance, function () use ($instance, $paths): void {
            $record = $this->checkoutReferences($instance->refresh()->getAttribute('vp_environment'), $instance->checkout_path);
            $stores = [];
            for ($reference = $record; is_array($reference); $reference = $reference['previous'] ?? null) {
                if (is_string($reference['store'] ?? null)) {
                    $stores[] = $reference['store'];
                }
            }
            $retained = $this->filterReferences($record, static fn (array $reference): bool => ($reference['checkout_path'] ?? null) === $instance->checkout_path
                || ! in_array($reference['checkout_path'] ?? null, $paths, true));
            $instance->update(['vp_environment' => $retained]);
            foreach (array_unique($stores) as $store) {
                $this->prune($instance, $store);
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function checkoutReferences(mixed $record, string $selectedPath): ?array
    {
        if (! is_array($record)) {
            return null;
        }

        return [...$record, 'checkout_path' => $record['checkout_path'] ?? $selectedPath,
            'previous' => $this->checkoutReferences($record['previous'] ?? null, $selectedPath)];
    }

    /**
     * @param  \Closure(array<string, mixed>): bool  $keep
     * @return array<string, mixed>|null
     */
    private function filterReferences(mixed $record, \Closure $keep): ?array
    {
        if (! is_array($record)) {
            return null;
        }
        $previous = $this->filterReferences($record['previous'] ?? null, $keep);

        return $keep($record) ? [...$record, 'previous' => $previous] : $previous;
    }

    /** @param \Closure(): void $callback */
    private function blocking(Instance $instance, \Closure $callback): void
    {
        $deadline = microtime(true) + 120;
        do {
            try {
                $this->lock->run($instance->node_id, ToolManagerName::Vp, $callback);

                return;
            } catch (ToolManagerScopeLockException $exception) {
                if (microtime(true) >= $deadline) {
                    throw $exception;
                }
                usleep(100_000);
            }
        } while (true);
    }

    private function prune(Instance $instance, string $store): void
    {
        $this->referenceTransaction($instance, function () use ($instance, $store): void {
            $references = [];
            foreach (Instance::query()->where('node_id', $instance->node_id)->whereNotNull('vp_environment')->pluck('vp_environment') as $record) {
                $this->references($record, $store, $references);
            }
            $this->vp->pruneEnvironments($instance->node, $store, $references);
        });
    }

    /** Serialize reference additions with collection, not store inspection or ordinary execution.
     * A no-op write also acquires SQLite's write reservation (SELECT FOR UPDATE does not).
     *
     * @param  \Closure(): mixed  $callback
     */
    private function referenceTransaction(Instance $instance, \Closure $callback): void
    {
        DB::transaction(function () use ($instance, $callback): void {
            DB::table('nodes')->where('id', $instance->node_id)->update(['id' => $instance->node_id]);
            $callback();
        });
    }

    /** @param list<array<string, mixed>> $references */
    private function references(mixed $record, string $store, array &$references): void
    {
        if (is_string($record)) {
            $record = json_decode($record, true);
        }
        if (! is_array($record)) {
            return;
        }
        if (($record['store'] ?? null) === $store) {
            $references[] = VpEnvironment::fromArray($record)->record('retained');
        }
        $this->references($record['previous'] ?? null, $store, $references);
    }
}
