<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Domain\Instances\Dependencies\CollectedDependencyFiles;
use App\Domain\Instances\Dependencies\DependencyCollectionException;
use App\Domain\Instances\Dependencies\DependencyEcosystem;
use App\Domain\Instances\Dependencies\DependencyInput;
use App\Domain\Instances\Dependencies\DependencySource;
use App\Infrastructure\Instances\DependencyFilesProgram;
use JsonException;
use stdClass;

final readonly class SelectDependencyInputAction
{
    private const array SIGNALS = [
        'npm-shrinkwrap.json' => 'npm', 'package-lock.json' => 'npm',
        'pnpm-lock.yaml' => 'pnpm', 'bun.lock' => 'bun', 'bun.lockb' => 'bun',
        'yarn.lock' => 'yarn', '.yarnrc.yml' => 'yarn',
        '.pnpmfile.cjs' => 'pnpm', 'pnpmfile.cjs' => 'pnpm',
        'bunfig.toml' => 'bun', 'yarn.config.cjs' => 'yarn',
    ];

    public function execute(CollectedDependencyFiles $files, DependencyEcosystem $ecosystem): DependencyInput
    {
        $names = $ecosystem === DependencyEcosystem::Composer
            ? ['composer.json', 'composer.lock']
            : array_values(array_diff(DependencyFilesProgram::FILES, ['composer.json', 'composer.lock']));
        foreach ($names as $name) {
            if (isset($files->errors[$name])) {
                throw new DependencyCollectionException($files->errors[$name], $name.' failed dependency file collection');
            }
            if (! array_key_exists($name, $files->contents) || ! array_key_exists($name, $files->hashes)) {
                throw new DependencyCollectionException('dependencies.invalid_collection');
            }
        }
        $manifestName = $ecosystem === DependencyEcosystem::Composer ? 'composer.json' : 'package.json';
        $manifest = $files->contents[$manifestName];
        try {
            $metadata = $manifest !== null ? $this->decode($manifest) : null;
        } catch (DependencyCollectionException $exception) {
            throw new DependencyCollectionException($exception->errorCode, $manifestName.' must contain a JSON object with unique keys');
        }
        if ($metadata !== null && property_exists($metadata, 'workspaces')) {
            throw new DependencyCollectionException('dependencies.unsupported_layout', $manifestName.' declares workspaces');
        }
        if ($ecosystem === DependencyEcosystem::Composer) {
            $manager = 'composer';
            $lockName = 'composer.lock';
        } else {
            $signals = [];
            foreach (self::SIGNALS as $name => $family) {
                if ($files->contents[$name] !== null) {
                    $signals[] = $family;
                }
            }
            $declared = $metadata !== null ? $this->declaredManagers($metadata) : [];
            $declared = array_values(array_unique($declared));
            if (count($declared) > 1) {
                throw new DependencyCollectionException('dependencies.ambiguous_manager', 'package.json packageManager and devEngines.packageManager select different managers');
            }
            if ($files->contents['pnpm-workspace.yaml'] !== null && ($declared === [] || $declared === ['pnpm'])) {
                throw new DependencyCollectionException('dependencies.unsupported_layout', 'pnpm-workspace.yaml declares an unsupported workspace layout');
            }
            $signals = $declared !== [] ? $declared : array_values(array_unique($signals));
            if (count($signals) > 1) {
                $present = array_keys(array_filter(self::SIGNALS, fn (string $family, string $name): bool => $files->contents[$name] !== null, ARRAY_FILTER_USE_BOTH));
                throw new DependencyCollectionException('dependencies.ambiguous_manager', 'conflicting manager files: '.implode(', ', $present));
            }
            if (in_array('yarn', $signals, true)) {
                throw new DependencyCollectionException('dependencies.unsupported_format', $files->contents['yarn.lock'] !== null
                    ? 'yarn.lock is not supported by the dependency scan'
                    : 'package.json or Yarn configuration selects unsupported Yarn');
            }
            $manager = $signals[0] ?? 'pnpm';
            $lockName = match ($manager) {
                'npm' => $files->contents['npm-shrinkwrap.json'] !== null ? 'npm-shrinkwrap.json' : 'package-lock.json',
                'bun' => 'bun.lock',
                default => 'pnpm-lock.yaml',
            };
            if ($manager === 'bun' && $files->contents['bun.lock'] === null && $files->contents['bun.lockb'] !== null) {
                throw new DependencyCollectionException('dependencies.unsupported_format', 'bun.lockb is binary; commit a text bun.lock to scan');
            }
            if ($manifest === null && $signals !== [] && $files->contents[$lockName] === null) {
                throw new DependencyCollectionException('dependencies.incomplete_source');
            }
        }
        $lock = $files->contents[$lockName];
        if (($manifest === null) !== ($lock === null)) {
            throw new DependencyCollectionException('dependencies.incomplete_source', $manifest === null ? $manifestName.' is missing for the selected lockfile' : $lockName.' is missing for the selected manager');
        }
        $absent = $manifest === null;

        return new DependencyInput(
            $ecosystem, $absent ? null : $manager, $manifest, $lock, $absent ? null : $lockName,
            new DependencySource($files->projectRoot, $files->reference, array_intersect_key($files->hashes, array_flip(array_values(array_filter($names, static fn (string $name): bool => ! str_starts_with($name, '.'))))), null),
        );
    }

    /** @return list<string> */
    private function declaredManagers(stdClass $manifest): array
    {
        $managers = [];
        if (property_exists($manifest, 'packageManager')) {
            if (! is_string($manifest->packageManager)
                || preg_match('/\A(npm|pnpm|bun|yarn)@(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?\z/D', $manifest->packageManager, $match) !== 1) {
                throw new DependencyCollectionException('dependencies.unsupported_format', 'package.json packageManager must name a supported manager with an exact version');
            }
            $managers[] = $match[1];
        }
        if (property_exists($manifest, 'devEngines')) {
            if (! $manifest->devEngines instanceof stdClass) {
                throw new DependencyCollectionException('dependencies.invalid_manifest');
            }
            if (property_exists($manifest->devEngines, 'packageManager')) {
                $entries = $manifest->devEngines->packageManager;
                $entries = is_array($entries) ? $entries : [$entries];
                $selected = null;
                $onFail = 'error';
                foreach ($entries as $entry) {
                    if (! $entry instanceof stdClass || ! is_string($entry->name ?? null)
                        || (isset($entry->version) && ! is_string($entry->version))
                        || (isset($entry->onFail) && ! in_array($entry->onFail, ['ignore', 'warn', 'error', 'download'], true))) {
                        throw new DependencyCollectionException('dependencies.invalid_manifest');
                    }
                    $onFail = $entry->onFail ?? 'error';
                    if (in_array($entry->name, ['npm', 'pnpm', 'bun', 'yarn'], true)) {
                        $selected = $entry->name;
                        break;
                    }
                }
                if ($selected !== null) {
                    $managers[] = $selected;
                } elseif (! in_array($onFail, ['warn', 'ignore'], true)) {
                    throw new DependencyCollectionException('dependencies.unsupported_format', 'package.json devEngines.packageManager has no supported manager');
                }
            }
        }

        return $managers;
    }

    private function decode(string $contents): stdClass
    {
        try {
            $value = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            if (! $value instanceof stdClass) {
                throw new DependencyCollectionException('dependencies.invalid_manifest');
            }
            preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]]/s', $contents, $tokens, PREG_OFFSET_CAPTURE);
            $objects = [];
            foreach ($tokens[0] as [$token, $offset]) {
                if ($token === '{' || $token === '[') {
                    $objects[] = [];
                } elseif ($token === '}' || $token === ']') {
                    array_pop($objects);
                } elseif (preg_match('/\G\s*:/', $contents, offset: $offset + strlen($token)) === 1) {
                    $key = json_decode($token, flags: JSON_THROW_ON_ERROR);
                    if (! is_string($key)) {
                        throw new DependencyCollectionException('dependencies.invalid_manifest');
                    }
                    $index = count($objects) - 1;
                    if (isset($objects[$index][$key])) {
                        throw new DependencyCollectionException('dependencies.invalid_manifest');
                    }
                    $objects[$index][$key] = true;
                }
            }

            return $value;
        } catch (JsonException) {
            throw new DependencyCollectionException('dependencies.invalid_manifest');
        }
    }
}
