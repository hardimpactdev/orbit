<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Domain\Instances\Dependencies\NpmVersionRange;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\VpEnvironment;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Instance;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class PrepareVpEnvironmentAction
{
    public function __construct(
        private VpToolManager $vp,
        private RemoteProgramRunner $programs,
        private ToolManagerScopeLock $lock,
        private RemoteProgramCatalog $catalog,
    ) {}

    public function command(Instance $instance, string $path, string $name, string $command): string
    {
        if ($name !== 'npm-build' && preg_match('/(?:^|[\s;&|\/])(vp|node|npm|npx|pnpm|yarn|bun|vite|corepack)(?:\s|$)/', $command) !== 1) {
            return $command;
        }
        try {
            $configuration = $this->readProject($instance, $path);
            $package = $configuration['package'] ?? null;
            if (! is_array($package)) {
                throw $this->invalid();
            }
            $dependencies = $package['devDependencies'] ?? [];
            $production = $package['dependencies'] ?? [];
            $spec = (is_array($dependencies) ? $dependencies['vite-plus'] ?? null : null)
                ?? (is_array($production) ? $production['vite-plus'] ?? null : null);
            if ($spec === null) {
                // A Project which drops vite-plus no longer owns a store requirement.
                $instance->update(['vp_environment' => null]);

                return $command;
            }
            if (! is_string($spec)) {
                throw $this->invalid();
            }
            $metadata = $this->packageVersion('vite-plus', $spec);
            $engine = $metadata['engines']['node'] ?? null;
            if (! is_string($engine)) {
                throw $this->invalid();
            }
            $engines = $package['engines'] ?? [];
            if (! is_array($engines)) {
                throw $this->invalid();
            }
            $selectedEngines = [];
            foreach (['node', 'npm', 'pnpm', 'yarn', 'bun'] as $family) {
                if (isset($engines[$family])) {
                    if (! is_string($engines[$family]) || ! $this->safeSelector($engines[$family])) {
                        throw $this->invalid();
                    }
                    $selectedEngines[$family] = $engines[$family];
                }
            }
            $engines = $selectedEngines;
            $nodeConstraint = $engine;
            if (isset($engines['node'])) {
                $intersections = [];
                foreach (explode('||', $engines['node']) as $projectRange) {
                    foreach (explode('||', $engine) as $viteRange) {
                        $intersections[] = trim($projectRange).' '.trim($viteRange);
                    }
                }
                $nodeConstraint = implode(' || ', $intersections);
            }
            $safe = [];
            // engines.node outranks .nvmrc in vp. Only supply a default when it cannot shadow a Project pin.
            if (isset($engines['node']) || ($configuration['.node-version'] ?? $configuration['.nvmrc'] ?? null) === null) {
                $safe['engines'] = ['node' => $nodeConstraint];
            }
            $manager = $package['packageManager'] ?? null;
            if ($manager === null) {
                $hint = $configuration['package_manager_hint'] ?? null;
                $family = is_string($hint) && in_array($hint, ['npm', 'pnpm', 'yarn', 'bun'], true) ? $hint : 'pnpm';
                $constraint = 'latest';
                foreach (['npm', 'pnpm', 'yarn', 'bun'] as $candidate) {
                    if (isset($engines[$candidate])) {
                        $family = $candidate;
                        $constraint = $engines[$candidate];
                        break;
                    }
                }
                $manager = $family.'@'.$constraint;
            }
            if (! is_string($manager) || preg_match('/\A(npm|pnpm|yarn|bun)@(.+)\z/D', $manager, $match) !== 1 || ! $this->safeSelector($match[2])) {
                throw $this->invalid();
            }
            $safe['packageManager'] = $manager;
            $configuration['package'] = $safe;
            $version = $metadata['version'];

            return $this->lock->run($instance->node_id, ToolManagerName::Vp, function () use ($instance, $configuration, $version, $engine, $engines, $name, $command): string {
                $this->vp->ensureVersion($instance->node, $version);
                $resolved = $this->vp->resolveEnvironment($instance->node, $configuration);
                $node = $resolved['node'];
                $pm = $resolved['package_manager'];
                if (! is_array($node) || ! is_array($pm) || ! is_string($node['version'] ?? null) || ! is_string($pm['name'] ?? null) || ! is_string($pm['version'] ?? null)) {
                    throw $this->invalid();
                }
                if (! (NpmVersionRange::parse($engine)?->satisfies($node['version']) ?? false)) {
                    throw $this->invalid();
                }
                if (isset($engines['node']) && ! (NpmVersionRange::parse($engines['node'])?->satisfies($node['version']) ?? false)) {
                    throw $this->invalid();
                }
                $pin = $configuration['.node-version'] ?? (isset($engines['node']) ? null : ($configuration['.nvmrc'] ?? null));
                $pinRange = is_string($pin) ? NpmVersionRange::parse($pin) : null;
                if ($pinRange !== null && ! $pinRange->satisfies($node['version'])) {
                    throw $this->invalid();
                }
                if (isset($engines[$pm['name']]) && ! (NpmVersionRange::parse($engines[$pm['name']])?->satisfies($pm['version']) ?? false)) {
                    throw $this->invalid();
                }
                $selector = $configuration['package']['packageManager'];
                $hash = preg_match('/\+(sha(?:224|256|384|512)\.[a-f0-9]+)$/i', $selector, $hashMatch) === 1 ? $hashMatch[1] : null;
                $environment = new VpEnvironment(dirname($this->vp->existingBinary($instance->node), 2), $version, $node['version'], $pm['name'], $pm['version'], $hash);
                $instance->update(['vp_environment' => $environment->record('installing')]);
                if ($hash !== null || ! $this->vp->environmentInstalled($instance->node, $environment)) {
                    $this->vp->installEnvironment($instance->node, $environment);
                    if (! $this->vp->environmentInstalled($instance->node, $environment)) {
                        throw new ResourceOperationException('deployment.vp_store_failed', 'The required Node runtime and package manager are still missing after installing the vp environment.', 502);
                    }
                }
                $instance->update(['vp_environment' => $environment->record('active')]);

                return $environment->command($name, $command);
            });
        } catch (Throwable $exception) {
            $record = $instance->getAttribute('vp_environment');
            $instance->update(['vp_environment' => [...(is_array($record) ? $record : []), 'status' => 'failed', 'error_code' => $exception instanceof ResourceOperationException ? $exception->errorCode : 'deployment.vp_store_failed']]);
            if ($exception instanceof ResourceOperationException) {
                throw $exception;
            }
            throw new ResourceOperationException('deployment.vp_store_failed', 'Could not prepare the vp store as its owner. The required Node runtime and package manager must be available before the build can run.', 502, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function readProject(Instance $instance, string $path): array
    {
        $user = null;
        if ($instance->placedOnAppProd()) {
            $user = $instance->production_user;
            if (! is_string($user) || preg_match('/\Aorbit-app-[0-9]+\z/D', $user) !== 1) {
                throw $this->invalid();
            }
        }
        $result = $this->programs->run($instance->node, $this->catalog->program('tools.read-vp-project', user: $user), ['path' => $path]);
        if ($result->outcome !== 'read') {
            throw $this->invalid();
        }
        $value = $result->data;
        if (! is_array($value['package'] ?? null)) {
            throw $this->invalid();
        }
        foreach (['.node-version', '.nvmrc'] as $file) {
            if (isset($value[$file]) && (! is_string($value[$file]) || ! $this->safeSelector(trim($value[$file])))) {
                throw $this->invalid();
            }
        }

        return $value;
    }

    /** @return array{version: string, engines: array<array-key, mixed>} */
    private function packageVersion(string $package, string $spec): array
    {
        if (! $this->safeSelector($spec)) {
            throw $this->invalid();
        }
        $response = Http::accept('application/vnd.npm.install-v1+json')->connectTimeout(5)->timeout(30)->get('https://registry.npmjs.org/'.$package)->throw()->json();
        if (! is_array($response) || ! is_array($response['versions'] ?? null)) {
            throw $this->invalid();
        }
        $tags = $response['dist-tags'] ?? [];
        $tag = is_array($tags) ? ($tags[$spec] ?? null) : null;
        $range = NpmVersionRange::parse(is_string($tag) ? $tag : $spec);
        $candidates = [];
        foreach ($response['versions'] as $version => $metadata) {
            if (is_string($version) && is_array($metadata) && preg_match('/\A\d+\.\d+\.\d+\z/D', $version) === 1 && ($range?->satisfies($version) ?? false)) {
                $candidates[$version] = $metadata;
            }
        }
        $versions = array_keys($candidates);
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
        $version = $versions[0] ?? null;
        $engines = $version === null ? null : ($candidates[$version]['engines'] ?? []);
        if ($version === null || ! is_array($engines)) {
            throw $this->invalid();
        }

        return ['version' => $version, 'engines' => $engines];
    }

    private function safeSelector(string $value): bool
    {
        return strlen($value) <= 255 && preg_match('/\A(?:[A-Za-z0-9.^~*+|<>= _-]+|lts\/[A-Za-z0-9*._-]+)\z/D', $value) === 1;
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('deployment.vp_requirements_invalid', 'The Project vite-plus, Node, or package-manager requirements are invalid or incompatible.', 422);
    }
}
