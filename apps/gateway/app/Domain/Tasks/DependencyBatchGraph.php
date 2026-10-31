<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\Dependencies\ReadBunDependencyGraphAction;
use App\Actions\Instances\Dependencies\ReadComposerDependencyGraphAction;
use App\Actions\Instances\Dependencies\ReadNpmDependencyGraphAction;
use App\Actions\Instances\Dependencies\ReadPnpmDependencyGraphAction;
use App\Domain\Instances\Dependencies\DependencyGraph;
use App\Domain\Instances\Dependencies\DependencyParseException;
use App\Domain\Shared\ResourceOperationException;
use stdClass;

/** Source checks run first; these readers bind the selected packages to the root. */
final readonly class DependencyBatchGraph
{
    public function __construct(
        private ReadComposerDependencyGraphAction $composer,
        private ReadNpmDependencyGraphAction $npm,
        private ReadPnpmDependencyGraphAction $pnpm,
        private ReadBunDependencyGraphAction $bun,
    ) {}

    public function validate(string $path, #[\SensitiveParameter] string $manifest, #[\SensitiveParameter] string $head, #[\SensitiveParameter] stdClass $base): DependencyGraph
    {
        try {
            $graph = match ($path) {
                'composer.lock' => $this->composer->execute($manifest, $head),
                'pnpm-lock.yaml' => $this->pnpm->execute($manifest, $head),
                'bun.lock' => $this->bun->execute($manifest, $head),
                default => $this->npm->execute($manifest, $head),
            };
            if (in_array($path, ['package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'bun.lock'], true)) {
                // No new JS package identity is authorized without registry edge attestation.
                $names = [];
                foreach ((array) ($base->packages ?? []) as $key => $record) {
                    if ($path === 'bun.lock') {
                        if (! is_array($record) || ! is_string($record[0] ?? null)) {
                            $this->invalid();
                        }
                        $name = substr($record[0], 0, (int) strrpos($record[0], '@'));
                    } elseif ($path === 'pnpm-lock.yaml') {
                        $separator = strrpos((string) $key, '@');
                        if ($separator === false || $separator === 0) {
                            $this->invalid();
                        }
                        $name = substr((string) $key, 0, $separator);
                    } else {
                        $parts = explode('node_modules/', (string) $key);
                        $name = array_last($parts);
                    }
                    $names[$name] = true;
                }
                foreach ($graph->resolutions as $resolution) {
                    if (! isset($names[$resolution->package->name])) {
                        $this->invalid();
                    }
                }
            }

            if ($path === 'composer.lock') {
                $names = [];
                foreach (array_merge($base->packages ?? [], $base->{'packages-dev'} ?? []) as $package) {
                    if (! $package instanceof stdClass || ! is_string($package->name ?? null)) {
                        $this->invalid();
                    }
                    $names[$package->name] = true;
                }
                foreach ($graph->requirements as $requirement) {
                    if ($requirement->to !== null && $requirement->to !== $requirement->name
                        && (! isset($names[$requirement->to]) || isset($names[$requirement->name]))) {
                        $this->invalid();
                    }
                }
            }

            return $graph;
        } catch (DependencyParseException) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new ResourceOperationException('dependency_merge.contents_forbidden', 'Lock packages must belong to the manifest dependency graph and approved package identities.', 409);
    }
}
