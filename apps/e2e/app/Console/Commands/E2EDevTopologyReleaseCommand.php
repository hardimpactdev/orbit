<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\DockerHost;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EDevTopologyManifestStore;
use App\E2E\Support\E2EResourceLease;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusTopologyTemplate;
use App\E2E\Support\SourceMountedCheckoutLifecycleLock;
use App\E2E\Support\SourceMountedCheckoutMutationFence;
use App\E2E\Support\SourceMountedCheckoutSyncer;
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
            return $this->renderError(
                'validation_failed',
                'A retained E2E topology id is required (or pass --all).',
                $json,
            );
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
        $provider = is_string($manifest['provider'] ?? null) ? $manifest['provider'] : 'incus';

        return match ($provider) {
            'docker' => $this->releaseDocker($store, $manifest),
            default => $this->releaseIncus($store, $manifest),
        };
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{id: string, reaped: list<string>, dry_run: bool}
     */
    private function releaseIncus(E2EDevTopologyManifestStore $store, array $manifest): array
    {
        $id = is_string($manifest['id'] ?? null) ? $manifest['id'] : '';
        $host = is_string($manifest['host'] ?? null) ? $manifest['host'] : '';
        $names = $this->instanceNames($manifest);
        $sourcePath = $this->validatedScopedSourcePath($manifest, $host);

        if ($sourcePath !== null) {
            $names = $this->expectedScopedInstanceNames($manifest, $id, $names);
        }

        if ($host === '') {
            $this->removeManifest($store, $id);

            return $this->releasedIncusPayload($id, $names);
        }

        $incusHost = $this->resolveHost($host);
        $releaseResources =
            fn (?SourceMountedCheckoutMutationFence $mutationFence = null) => $this->releaseIncusHostResources(
                $incusHost,
                $manifest,
                $names,
                $sourcePath,
                $mutationFence,
            );

        if ($sourcePath === null) {
            $releaseResources(null);
            $this->removeManifest($store, $id);

            return $this->releasedIncusPayload($id, $names);
        }

        new SourceMountedCheckoutLifecycleLock($host, $sourcePath)->run(static function (
            SourceMountedCheckoutMutationFence $mutationFence,
        ) use ($releaseResources, $store, $id): void {
            $releaseResources($mutationFence);

            if ($id !== '') {
                $store->remove($id);
            }
        });

        return $this->releasedIncusPayload($id, $names);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $names
     */
    private function releaseIncusHostResources(
        IncusHost $incusHost,
        array $manifest,
        array $names,
        ?string $sourcePath,
        ?SourceMountedCheckoutMutationFence $mutationFence,
    ): void {
        $id = is_string($manifest['id'] ?? null) ? $manifest['id'] : '';
        $host = $incusHost->config->host;

        if ($names !== []) {
            $result = $incusHost->deleteInstancesIfPresent($names);

            if (! $result->successful()) {
                throw new \RuntimeException(
                    "Failed to reap retained topology [{$id}] on {$host}: {$result->errorOutput()}",
                );
            }
        }

        $this->removeSshKey($incusHost, $manifest);

        if ($sourcePath !== null) {
            if ($mutationFence === null) {
                throw new \LogicException('Retained source cleanup requires an active mutation generation.');
            }

            $this->removeScopedSourcePath($incusHost, $id, $sourcePath, $mutationFence);
        }
    }

    private function removeManifest(E2EDevTopologyManifestStore $store, string $id): void
    {
        if ($id !== '') {
            $store->remove($id);
        }
    }

    /**
     * @param  list<string>  $names
     * @return array{id: string, reaped: list<string>, dry_run: bool}
     */
    private function releasedIncusPayload(string $id, array $names): array
    {
        return [
            'id' => $id,
            'reaped' => $names,
            'dry_run' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{id: string, reaped: list<string>, dry_run: bool}
     */
    private function releaseDocker(E2EDevTopologyManifestStore $store, array $manifest): array
    {
        $id = is_string($manifest['id'] ?? null) ? $manifest['id'] : '';
        $hostName = is_string($manifest['host'] ?? null) && $manifest['host'] !== ''
            ? $manifest['host']
            : 'local';
        $host = new DockerHost(E2EConfig::fromEnvironment(), $hostName);
        $containers = $this->dockerContainerNames($manifest);
        $volumes = $this->dockerVolumeNames($manifest);
        $network = $this->dockerNetwork($manifest);

        if ($containers !== []) {
            $host->run(sprintf(
                'docker rm -f %s >/dev/null 2>&1 || true',
                implode(' ', array_map(escapeshellarg(...), $containers)),
            ), timeoutSeconds: 120);
        }

        if ($volumes !== []) {
            $host->run(sprintf(
                'docker volume rm -f %s >/dev/null 2>&1 || true',
                implode(' ', array_map(escapeshellarg(...), $volumes)),
            ), timeoutSeconds: 120);
        }

        if ($network !== '') {
            $host->run(sprintf(
                'docker network rm %s >/dev/null 2>&1 || true',
                escapeshellarg($network),
            ), timeoutSeconds: 30);
        }

        $this->releaseResourceLease($manifest);

        if ($id !== '') {
            $store->remove($id);
        }

        return [
            'id' => $id,
            'reaped' => $containers,
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

    /** @param array<string, mixed> $manifest */
    private function validatedScopedSourcePath(array $manifest, string $host): ?string
    {
        $id = is_string($manifest['id'] ?? null) ? $manifest['id'] : '';
        $runId = is_string($manifest['run_id'] ?? null) ? $manifest['run_id'] : '';
        $sourcePath = is_string($manifest['source_path'] ?? null) ? $manifest['source_path'] : '';

        if ($id === '' || $sourcePath === '') {
            return null;
        }

        if (! hash_equals($id, $runId)) {
            throw new \RuntimeException(
                "Retained topology identity mismatch: manifest id [{$id}], run id [{$runId}].",
            );
        }

        if ($this->isLocalHost($host)) {
            return null;
        }

        $expectedPath = new SourceMountedCheckoutSyncer()->sourcePath($host, 'incus', $id);

        if (! hash_equals($expectedPath, $sourcePath)) {
            throw new \RuntimeException(
                "Refusing to remove unexpected retained source path [{$sourcePath}] for topology [{$id}].",
            );
        }

        return $sourcePath;
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['', 'localhost', '127.0.0.1', '::1'], strict: true);
    }

    private function removeScopedSourcePath(
        IncusHost $host,
        string $id,
        string $sourcePath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): void {
        $result = $host->run(
            $mutationFence->guardedScript(
                SourceMountedCheckoutMutationFence::protectedSourceCleanupScript($sourcePath),
            ),
            timeoutSeconds: 120,
        );

        if (! $result->successful()) {
            throw new \RuntimeException(
                "Failed to remove retained source path [{$sourcePath}] for topology [{$id}]: ".$result->errorOutput(),
            );
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

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $recordedNames
     * @return list<string>
     */
    private function expectedScopedInstanceNames(array $manifest, string $id, array $recordedNames): array
    {
        $kindValue = is_string($manifest['kind'] ?? null) ? $manifest['kind'] : '';
        $kind = E2ETopologyKind::tryFromInput($kindValue);

        if ($id === '' || $kind === null) {
            throw new \RuntimeException(
                "Retained topology [{$id}] does not record a valid topology kind; refusing source cleanup.",
            );
        }

        $expectedNames = array_map(
            static fn (string $role): string => IncusTopologyTemplate::cloneName($id, $role),
            IncusTopologyTemplate::rolesFor($kind),
        );
        $unexpectedNames = array_diff($recordedNames, $expectedNames);

        if ($unexpectedNames !== []) {
            throw new \RuntimeException(
                "Retained topology [{$id}] records unexpected instance names; refusing source cleanup.",
            );
        }

        return $expectedNames;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function dockerContainerNames(array $manifest): array
    {
        $containers = $manifest['managed_containers'] ?? null;

        if (is_array($containers)) {
            return $this->stringList($containers);
        }

        $names = [];
        $instances = $manifest['instances'] ?? [];

        if (! is_array($instances)) {
            return [];
        }

        foreach ($instances as $role => $nodeContainer) {
            if (! is_string($role) || ! is_string($nodeContainer) || $nodeContainer === '') {
                continue;
            }

            if ($role === 'gateway') {
                $names[] = "{$nodeContainer}-orbit-gateway";
            }

            $names[] = "{$nodeContainer}-orbit-caddy";
            $names[] = $nodeContainer;
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function dockerVolumeNames(array $manifest): array
    {
        $volumes = $manifest['volumes'] ?? null;

        if (is_array($volumes)) {
            return $this->stringList($volumes);
        }

        $names = [];
        $instances = $manifest['instances'] ?? [];

        if (! is_array($instances)) {
            return [];
        }

        foreach ($instances as $nodeContainer) {
            if (! is_string($nodeContainer) || $nodeContainer === '') {
                continue;
            }

            array_push(
                $names,
                "{$nodeContainer}-home-orbit",
                "{$nodeContainer}-etc-caddy",
                "{$nodeContainer}-etc-orbit",
                "{$nodeContainer}-opt-orbit",
            );
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function dockerNetwork(array $manifest): string
    {
        $network = $manifest['network'] ?? null;

        if (is_string($network) && $network !== '') {
            return $network;
        }

        $runId = $manifest['run_id'] ?? null;

        if (! is_string($runId) || $runId === '') {
            return '';
        }

        return E2EConfig::fromEnvironment()->instancePrefix."-{$runId}";
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return array_values(array_unique($strings));
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function releaseResourceLease(array $manifest): void
    {
        $metadata = $manifest['resource_lease'] ?? null;

        if (! is_array($metadata)) {
            return;
        }

        try {
            E2EResourceLease::fromMetadata($metadata)->release();
        } catch (Throwable) {
            // Resource cleanup is the important part. Stale lease files can be
            // inspected manually if their recorded metadata was malformed.
        }
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
