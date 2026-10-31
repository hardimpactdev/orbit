<?php

declare(strict_types=1);

namespace App\Domain\Instances\VpEnvironment;

use App\Domain\Tools\VpEnvironment;
use App\Models\Instance;
use Illuminate\Support\Facades\DB;

/** Stores public runtime references reported by native parent DAGs. */
final readonly class InstanceVpEnvironmentMaterial
{
    public static function required(string $name, string $command): bool
    {
        return $name === 'npm-build' || preg_match('/(?:^|[\s;&|\/])(vp|node|npm|npx|pnpm|yarn|bun|vite|corepack)(?:\s|$)/', $command) === 1;
    }

    /** Store public pins for a checkout already validated by its owning parent. Performs no runtime action. */
    public function recordReference(Instance $instance, string $path, VpEnvironment $environment, string $status): void
    {
        DB::transaction(function () use ($instance, $path, $environment, $status): void {
            DB::table('nodes')->where('id', $instance->node_id)->update(['id' => $instance->node_id]);
            $previous = $this->checkoutReferences($instance->refresh()->getAttribute('vp_environment'), $instance->checkout_path);
            $identity = $environment->record('checking');
            unset($identity['status']);
            $previous = $this->filter($previous, static function (array $record) use ($path, $identity): bool {
                foreach ($identity as $key => $value) {
                    if (($record[$key] ?? null) !== $value) {
                        return true;
                    }
                }

                return ($record['checkout_path'] ?? null) !== $path;
            });
            $instance->update(['vp_environment' => [...$environment->record($status), 'checkout_path' => $path, 'previous' => $previous]]);
        });
    }

    /** @return list<string> */
    public function stores(Instance $instance): array
    {
        $stores = [];
        for ($record = $instance->getAttribute('vp_environment'); is_array($record); $record = $record['previous'] ?? null) {
            if (is_string($record['store'] ?? null)) {
                $stores[] = $record['store'];
            }
        }

        return array_values(array_unique($stores));
    }

    /** @return list<array<string,?string>> */
    public function references(int $nodeId, string $store): array
    {
        $references = [];
        foreach (Instance::query()->where('node_id', $nodeId)->orderBy('id')->get() as $instance) {
            for ($record = $instance->getAttribute('vp_environment'); is_array($record); $record = $record['previous'] ?? null) {
                if (($record['store'] ?? null) === $store) {
                    $references[] = VpEnvironment::fromArray($record)->input();
                }
            }
        }

        return $references;
    }

    /** @param list<string> $paths */
    public function retire(Instance $instance, array $paths): void
    {
        DB::transaction(function () use ($instance, $paths): void {
            DB::table('nodes')->where('id', $instance->node_id)->update(['id' => $instance->node_id]);
            $record = $this->checkoutReferences($instance->refresh()->getAttribute('vp_environment'), $instance->checkout_path);
            $instance->update(['vp_environment' => $this->filter($record, static fn (array $reference): bool => ($reference['checkout_path'] ?? null) === $instance->checkout_path
                || ! in_array($reference['checkout_path'] ?? null, $paths, true))]);
        });
    }

    /** @return array<string,mixed>|null */
    private function checkoutReferences(mixed $record, string $selectedPath): ?array
    {
        return is_array($record) ? [...$record, 'checkout_path' => $record['checkout_path'] ?? $selectedPath,
            'previous' => $this->checkoutReferences($record['previous'] ?? null, $selectedPath)] : null;
    }

    /** @return array<string,mixed>|null */
    private function filter(mixed $record, \Closure $keep): ?array
    {
        if (! is_array($record)) {
            return null;
        }
        $previous = $this->filter($record['previous'] ?? null, $keep);

        return $keep($record) ? [...$record, 'previous' => $previous] : $previous;
    }
}
