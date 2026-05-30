<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\IncusHost;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('e2e:dev-topology:release
    {id? : The retained dev-topology id to release}
    {--all : Release every recorded retained dev topology}
    {--json : Output as JSON}')]
#[Description('Reap a retained prepared topology and remove its recorded state')]
class E2EDevTopologyReleaseCommand extends Command
{
    /**
     * @var (Closure(string): IncusHost)|null
     */
    private ?Closure $hostFactory = null;

    /**
     * Override how a host name resolves to an IncusHost so unit tests can assert
     * which instances get reaped without reaching beast.
     *
     * @param  Closure(string): IncusHost  $factory
     */
    public function hostFactoryUsing(Closure $factory): void
    {
        $this->hostFactory = $factory;
    }

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $store = E2EDevTopologyManifestStore::fromEnvironment(repo_path());

        if ((bool) $this->option('all')) {
            return $this->releaseAll($store, $json);
        }

        $id = $this->argument('id');

        if (! is_string($id) || trim($id) === '') {
            return $this->renderError('validation_failed', 'A retained E2E topology id is required (or pass --all).', $json);
        }

        $id = trim($id);

        // Releasing the dry-run placeholder is a no-op success so the documented
        // example command never fails on a topology that was never provisioned.
        if ($id === 'dry-run') {
            return $this->renderReleased([['id' => 'dry-run', 'reaped' => [], 'dry_run' => true]], $json);
        }

        $manifest = $store->read($id);

        if ($manifest === null) {
            return $this->renderError('not_found', "Retained E2E topology [{$id}] was not found.", $json);
        }

        try {
            $released = $this->release($store, $manifest);
        } catch (Throwable $exception) {
            return $this->renderError('release_failed', $exception->getMessage(), $json);
        }

        return $this->renderReleased([$released], $json);
    }

    private function releaseAll(E2EDevTopologyManifestStore $store, bool $json): int
    {
        $released = [];

        foreach ($store->list() as $manifest) {
            try {
                $released[] = $this->release($store, $manifest);
            } catch (Throwable $exception) {
                return $this->renderError('release_failed', $exception->getMessage(), $json);
            }
        }

        return $this->renderReleased($released, $json);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{id: string, reaped: list<string>, dry_run: bool}
     */
    private function release(E2EDevTopologyManifestStore $store, array $manifest): array
    {
        $id = is_string($manifest['id'] ?? null) ? $manifest['id'] : '';
        $host = is_string($manifest['host'] ?? null) ? $manifest['host'] : '';
        $names = $this->instanceNames($manifest);

        if ($host !== '' && $names !== []) {
            $incusHost = $this->resolveHost($host);
            $result = $incusHost->deleteInstancesIfPresent($names);

            if (! $result->successful()) {
                throw new \RuntimeException("Failed to reap retained topology [{$id}] on {$host}: {$result->errorOutput()}");
            }

            $this->removeSshKey($incusHost, $manifest);
        }

        if ($id !== '') {
            $store->remove($id);
        }

        return [
            'id' => $id,
            'reaped' => $names,
            'dry_run' => false,
        ];
    }

    /**
     * Remove the dedicated SSH key directory the provider created on the host for
     * this retained run (`/tmp/orbit-e2e-topology-<run_id>`).
     *
     * @param  array<string, mixed>  $manifest
     */
    private function removeSshKey(IncusHost $host, array $manifest): void
    {
        $keyPath = is_string($manifest['ssh_key_path'] ?? null) ? $manifest['ssh_key_path'] : '';

        if ($keyPath === '' || ! str_starts_with($keyPath, '/tmp/orbit-e2e-topology-')) {
            return;
        }

        $keyDirectory = dirname($keyPath);

        try {
            $host->run('rm -rf '.escapeshellarg($keyDirectory), timeoutSeconds: 30);
        } catch (Throwable) {
            // Reaping the instances is the important part; a leftover key dir is
            // harmless and gets cleared on the next run for the same id.
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function instanceNames(array $manifest): array
    {
        $instances = $manifest['instances'] ?? [];

        if (! is_array($instances)) {
            return [];
        }

        $names = [];

        foreach ($instances as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function resolveHost(string $host): IncusHost
    {
        if ($this->hostFactory !== null) {
            return ($this->hostFactory)($host);
        }

        return new IncusHost(E2EConfig::fromEnvironment()->forHost($host));
    }

    /**
     * @param  list<array{id: string, reaped: list<string>, dry_run: bool}>  $released
     */
    private function renderReleased(array $released, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'success' => [
                    'released' => $released,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        if ($released === []) {
            $this->line('No retained E2E topologies to release.');

            return self::SUCCESS;
        }

        foreach ($released as $entry) {
            $count = count($entry['reaped']);
            $this->line("Released retained E2E topology [{$entry['id']}].");
            $this->line("Instances reaped: {$count}");
        }

        return self::SUCCESS;
    }

    private function renderError(string $code, string $message, bool $json): int
    {
        if ($json) {
            $this->line(json_encode([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->components->error($message);

        return self::FAILURE;
    }
}
