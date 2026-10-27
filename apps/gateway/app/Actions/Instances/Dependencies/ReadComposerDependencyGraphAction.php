<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Domain\Instances\Dependencies\DependencyEcosystem;
use App\Domain\Instances\Dependencies\DependencyGraph;
use App\Domain\Instances\Dependencies\DependencyIdentity;
use App\Domain\Instances\Dependencies\DependencyParseException;
use App\Domain\Instances\Dependencies\DependencyRequirement;
use App\Domain\Instances\Dependencies\DependencyRequirementKind;
use App\Domain\Instances\Dependencies\DependencyResolution;
use App\Domain\Instances\Dependencies\DependencyScope;
use Composer\Semver\VersionParser;
use InvalidArgumentException;
use JsonException;
use stdClass;
use UnexpectedValueException;

final readonly class ReadComposerDependencyGraphAction
{
    public function execute(string $manifestContents, string $lockContents): DependencyGraph
    {
        try {
            return $this->read($this->decode($manifestContents, 'composer.json'), $this->decode($lockContents, 'composer.lock'));
        } catch (JsonException|UnexpectedValueException|InvalidArgumentException) {
            // Do not attach an exception that can contain raw source or credentials.
            throw new DependencyParseException(errorDetail: 'composer.json or composer.lock contains an invalid Composer version constraint');
        }
    }

    private function decode(string $contents, string $file): stdClass
    {
        try {
            $value = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DependencyParseException(errorDetail: $file.' is not valid JSON');
        }

        if (! $value instanceof stdClass) {
            throw new DependencyParseException(errorDetail: $file.' must contain a JSON object');
        }

        try {
            $this->rejectDuplicateKeys($contents);
        } catch (DependencyParseException) {
            throw new DependencyParseException(errorDetail: $file.' contains duplicate object keys');
        }

        return $value;
    }

    private function rejectDuplicateKeys(string $contents): void
    {
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
                    throw new DependencyParseException;
                }
                $index = count($objects) - 1;

                if (isset($objects[$index][$key])) {
                    throw new DependencyParseException;
                }

                $objects[$index][$key] = true;
            }
        }
    }

    private function read(stdClass $manifest, stdClass $lock): DependencyGraph
    {
        $this->validateLayout($manifest);
        $packages = [];

        foreach (['packages', 'packages-dev'] as $section) {
            $records = $lock->{$section} ?? null;

            if (! is_array($records) || ! array_is_list($records)) {
                throw new DependencyParseException(errorDetail: 'composer.lock packages and packages-dev must be lists');
            }

            foreach ($records as $record) {
                if (! $record instanceof stdClass) {
                    throw new DependencyParseException(errorDetail: 'composer.lock package record must be an object');
                }

                $name = $this->packageName($record->name ?? null);

                if (isset($packages[$name])) {
                    throw new DependencyParseException(errorDetail: 'composer.lock contains a duplicate package');
                }

                $this->validateLayout($record);
                $this->safeText($record->version ?? null);
                $packages[$name] = $record;
            }
        }

        ksort($packages, SORT_STRING);
        $ids = [];
        foreach ($packages as $name => $package) {
            $ids[$name] = ($package->dist->type ?? null) === 'path' ? 'path:'.$name : $name;
        }
        $this->validateAliases($lock, $packages);
        $providers = $this->providers($manifest, $packages);
        $requirements = [];

        foreach ([DependencyScope::Regular, DependencyScope::Development] as $scope) {
            $field = $scope === DependencyScope::Regular ? 'require' : 'require-dev';

            foreach ($this->links($manifest, $field) as $name => $constraint) {
                $requirements[] = $this->requirement(null, $name, $constraint, $scope, $packages, $providers);
            }
        }

        foreach ($packages as $name => $package) {
            foreach ($this->links($package, 'require') as $target => $constraint) {
                $requirements[] = $this->requirement($name, $target, $constraint, DependencyScope::Regular, $packages, $providers);
            }

            // Dependency require-dev is metadata, not an installed dependency path.
            $this->links($package, 'require-dev');
        }

        $regular = $this->reachable($requirements, DependencyScope::Regular);
        $development = $this->reachable($requirements, DependencyScope::Development);
        $resolutions = [];

        foreach ($packages as $name => $package) {
            if (! isset($regular[$name]) && ! isset($development[$name])) {
                throw new DependencyParseException(errorDetail: 'composer.lock contains a package unreachable from composer.json requirements');
            }

            $resolutions[] = new DependencyResolution(
                id: $ids[$name],
                package: new DependencyIdentity(DependencyEcosystem::Composer, $name),
                version: $this->safeText($package->version),
                regular: isset($regular[$name]),
                development: isset($development[$name]),
                sourceReference: $this->sourceReference($package),
                integrity: $this->integrity($package),
            );
        }

        $requirements = array_map(static fn (DependencyRequirement $edge): DependencyRequirement => new DependencyRequirement(
            $edge->from === null ? null : $ids[$edge->from],
            $edge->to === null ? null : $ids[$edge->to],
            $edge->name, $edge->constraint, $edge->kind, $edge->scope, $edge->optional,
        ), $requirements);

        return new DependencyGraph(DependencyEcosystem::Composer, $resolutions, $requirements);
    }

    /** @return array<string, string> */
    private function links(stdClass $record, string $field): array
    {
        if (! property_exists($record, $field)) {
            return [];
        }

        if (! $record->{$field} instanceof stdClass) {
            throw new DependencyParseException(errorDetail: 'Composer '.$field.' must be a constraint map');
        }

        $links = [];

        foreach (get_object_vars($record->{$field}) as $name => $constraint) {
            $canonical = $this->requirementName($name);

            if (isset($links[$canonical])) {
                throw new DependencyParseException(errorDetail: 'Composer constraint map contains duplicate canonical package names');
            }

            $constraint = $this->safeText($constraint);
            $links[$canonical] = $constraint;

            if ($constraint !== 'self.version' || ! in_array($field, ['provide', 'replace'], true)) {
                (new VersionParser)->parseConstraints($constraint);
            }
        }

        ksort($links, SORT_STRING);

        return $links;
    }

    /**
     * @param  array<string, stdClass>  $packages
     * @return array<string, list<string|null>>
     */
    private function providers(stdClass $manifest, array $packages): array
    {
        $providers = [];

        if (property_exists($manifest, 'name')) {
            $providers[$this->packageName($manifest->name)] = [null];
        }

        foreach (['provide', 'replace'] as $field) {
            foreach ($this->links($manifest, $field) as $name => $constraint) {
                $providers[$name][] = null;
            }

            foreach ($packages as $packageName => $package) {
                foreach ($this->links($package, $field) as $name => $constraint) {
                    $providers[$name][] = $packageName;
                }
            }
        }

        foreach ($providers as $name => $targets) {
            $providers[$name] = array_values(array_unique($targets, SORT_REGULAR));
        }

        return $providers;
    }

    /**
     * @param  array<string, stdClass>  $packages
     * @param  array<string, list<string|null>>  $providers
     */
    private function requirement(?string $from, string $name, string $constraint, DependencyScope $scope, array $packages, array $providers): DependencyRequirement
    {
        $target = null;

        if (! $this->isPlatform($name)) {
            if (isset($packages[$name])) {
                $target = $name;
            } elseif (array_key_exists($name, $providers) && count($providers[$name]) === 1) {
                $target = $providers[$name][0];
            } else {
                throw new DependencyParseException(errorDetail: 'composer.json or composer.lock requirement has no unique locked package or provider');
            }
        }

        return new DependencyRequirement($from, $target, $name, $constraint, DependencyRequirementKind::Dependency, $scope);
    }

    /**
     * @param  list<DependencyRequirement>  $requirements
     * @return array<string, true>
     */
    private function reachable(array $requirements, DependencyScope $scope): array
    {
        $children = [];
        $pending = [];

        foreach ($requirements as $requirement) {
            if ($requirement->to === null) {
                continue;
            }

            if ($requirement->from === null) {
                if ($requirement->scope === $scope) {
                    $pending[] = $requirement->to;
                }
            } else {
                $children[$requirement->from][] = $requirement->to;
            }
        }

        $seen = [];

        while ($pending !== []) {
            $name = array_pop($pending);

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            foreach ($children[$name] ?? [] as $child) {
                $pending[] = $child;
            }
        }

        return $seen;
    }

    /** @param array<string, stdClass> $packages */
    private function validateAliases(stdClass $lock, array $packages): void
    {
        $aliases = property_exists($lock, 'aliases') ? $lock->aliases : [];

        if (! is_array($aliases)) {
            throw new DependencyParseException(errorDetail: 'composer.lock aliases must be a list');
        }

        foreach ($aliases as $alias) {
            if (! $alias instanceof stdClass) {
                throw new DependencyParseException(errorDetail: 'composer.lock alias must be an object');
            }

            $name = $this->packageName($alias->package ?? null);
            $version = $this->safeText($alias->version ?? null);
            $aliasVersion = $this->safeText($alias->alias ?? null);
            $normalized = $this->safeText($alias->alias_normalized ?? null);
            $parser = new VersionParser;

            if (! isset($packages[$name])
                || $parser->normalize($version) !== $parser->normalize($this->safeText($packages[$name]->version))
                || $parser->normalize($aliasVersion) !== $normalized) {
                throw new DependencyParseException(errorDetail: 'composer.lock alias does not match its locked package or normalized version');
            }
        }
    }

    private function validateLayout(stdClass $record): void
    {
        if (property_exists($record, 'workspaces')) {
            throw new DependencyParseException('dependencies.unsupported_layout');
        }

        if (property_exists($record, 'repositories')) {
            if (is_array($record->repositories)) {
                $repositories = $record->repositories;
            } elseif ($record->repositories instanceof stdClass) {
                $repositories = get_object_vars($record->repositories);
            } else {
                throw new DependencyParseException(errorDetail: 'Composer repositories must be a list or object');
            }

            foreach ($repositories as $repository) {
                if ($repository === false) {
                    continue;
                }

                if (! $repository instanceof stdClass) {
                    throw new DependencyParseException(errorDetail: 'Composer repository entry must be an object or false');
                }

                if (array_values(get_object_vars($repository)) === [false]) {
                    continue;
                }

                if (! is_string($repository->type ?? null)) {
                    throw new DependencyParseException(errorDetail: 'Composer repository entry is missing its type');
                }
            }
        }

        foreach (['source', 'dist'] as $field) {
            if (property_exists($record, $field) && ! $record->{$field} instanceof stdClass) {
                throw new DependencyParseException(errorDetail: 'composer.lock '.$field.' must be an object');
            }
        }
    }

    private function sourceReference(stdClass $package): ?string
    {
        $references = [];

        foreach (['source', 'dist'] as $field) {
            $reference = $package->{$field}->reference ?? null;

            if ($reference !== null && $reference !== '') {
                if (! is_string($reference) || preg_match('/^[^\s:@?\x00-\x1f\x7f]+$/D', $reference) !== 1) {
                    throw new DependencyParseException(errorDetail: 'composer.lock source or dist reference failed safe revision validation');
                }

                $references[] = $reference;
            }
        }

        return $references[0] ?? null;
    }

    private function integrity(stdClass $package): ?string
    {
        $shasum = $package->dist->shasum ?? null;

        if ($shasum === null || $shasum === '') {
            return null;
        }

        if (! is_string($shasum) || preg_match('/^[a-fA-F0-9]{40}$/D', $shasum) !== 1) {
            throw new DependencyParseException(errorDetail: 'composer.lock dist shasum must be a SHA-1 digest');
        }

        return $shasum;
    }

    private function safeText(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '' || preg_match('/[\x00-\x1f\x7f:?\\\\]|[^ ]@[^ ]+\./', $value) === 1) {
            throw new DependencyParseException(errorDetail: 'Composer version or constraint failed safe text validation');
        }

        return $value;
    }

    private function packageName(mixed $value): string
    {
        if (! is_string($value) || preg_match('{^[a-z0-9](?:[_.-]?[a-z0-9]+)*/[a-z0-9](?:(?:[_.]?|-{0,2})[a-z0-9]+)*$}iD', $value) !== 1) {
            throw new DependencyParseException(errorDetail: 'Composer package name failed vendor/name validation');
        }

        return strtolower($value);
    }

    private function requirementName(mixed $value): string
    {
        if (! is_string($value)) {
            throw new DependencyParseException(errorDetail: 'Composer requirement name must be a string');
        }

        $value = strtolower($value);

        return $this->isPlatform($value) ? $value : $this->packageName($value);
    }

    private function isPlatform(string $name): bool
    {
        return preg_match('/^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|composer(?:-plugin-api|-runtime-api)?|(?:ext|lib)-[a-z0-9_.-]+)$/D', $name) === 1;
    }
}
