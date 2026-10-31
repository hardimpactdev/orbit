<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Instances\Dependencies\DependencyGraph;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Dependencies\PackagistDependencyMetadata;
use JsonException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** A path allowlist cannot authorize changes to build, test or acquisition instructions. */
final readonly class DependencyBatchContents
{
    public const array Files = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock'];

    private const string NpmName = '(?:@[a-z0-9-][a-z0-9_.-]*/)?[a-z0-9-][a-z0-9_.-]*';

    private const string PackageVersion = '~\A('.self::NpmName.')@([0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?)\z~D';

    public function __construct(private PackagistDependencyMetadata $packagist, private DependencyBatchVersions $versions, private DependencyBatchGraph $graph) {}

    public function validate(string $path, #[\SensitiveParameter] string $head, #[\SensitiveParameter] ?string $base = null, #[\SensitiveParameter] ?string $manifest = null): void
    {
        try {
            if (in_array($path, ['composer.json', 'package.json'], true)) {
                $this->manifest($path, $this->json($base ?? ''), $this->json($head));
            } elseif ($path === 'composer.lock') {
                $this->composer($this->json($head));
            } elseif (in_array($path, ['package-lock.json', 'npm-shrinkwrap.json'], true)) {
                $this->npm($this->json($head));
            } elseif ($path === 'pnpm-lock.yaml') {
                $lock = Yaml::parse($head, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
                $this->check($lock instanceof stdClass && in_array($lock->lockfileVersion ?? null, ['9.0', 9.0], true));
                $this->references($lock);
                $this->pnpmEdges($lock);
                foreach ($this->map($lock->packages ?? null) as $id => $package) {
                    $this->check(is_string($id) && preg_match(self::PackageVersion, $id, $identity) === 1 && $package instanceof stdClass);
                    $name = $identity[1];
                    $version = $identity[2];
                    $this->check((! property_exists($package, 'name') || $package->name === $name) && (! property_exists($package, 'version') || $package->version === $version));
                    $resolution = $package->resolution ?? null;
                    $this->check($resolution instanceof stdClass);
                    $this->keys($resolution, ['integrity']);
                    $this->integrity($resolution->integrity ?? null);
                }
            } elseif ($path === 'bun.lock') {
                $lock = $this->json($this->jsonc($head));
                $this->check(in_array($lock->lockfileVersion ?? null, [1, 2], true));
                $this->references($lock);
                foreach ($this->map($lock->packages ?? null) as $key => $package) {
                    $this->check(is_array($package) && array_is_list($package) && count($package) === 4
                        && is_string($key) && is_string($package[0]) && preg_match(self::PackageVersion, $package[0], $idParts) === 1 && $package[1] === '');
                    $identity = $idParts[1];
                    $parts = explode('/', $key);
                    $name = array_pop($parts);
                    if ($parts !== [] && str_starts_with(array_last($parts), '@')) {
                        $name = array_pop($parts).'/'.$name;
                    }
                    $this->check($identity === $name);
                    $this->integrity($package[3]);
                }
            } else {
                $this->forbidden();
            }
            if ($base !== null && $manifest !== null) {
                $graph = null;
                if (! in_array($path, ['composer.json', 'package.json'], true)) {
                    $before = $path === 'pnpm-lock.yaml' ? Yaml::parse($base, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE) : $this->json($path === 'bun.lock' ? $this->jsonc($base) : $base);
                    $this->check($before instanceof stdClass);
                    $graph = $this->graph->validate($path, $manifest, $head, $before);
                }
                $this->lockedMajors($path, $base, $head, $manifest, $graph);
            }
        } catch (JsonException|ParseException) {
            throw new ResourceOperationException('dependency_merge.contents_unreadable', 'Dependency content could not be decoded.', 409);
        }
    }

    private function manifest(string $path, #[\SensitiveParameter] stdClass $base, #[\SensitiveParameter] stdClass $head): void
    {
        $fields = $path === 'composer.json' ? ['require', 'require-dev'] : ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'];
        foreach ($fields as $field) {
            $before = property_exists($base, $field) ? $this->map($base->{$field}) : [];
            $after = property_exists($head, $field) ? $this->map($head->{$field}) : [];
            $oldKeys = array_keys($before);
            $newKeys = array_keys($after);
            sort($oldKeys);
            sort($newKeys);
            $this->check($oldKeys === $newKeys && property_exists($base, $field) === property_exists($head, $field));
            foreach ($before as $name => $constraint) {
                $this->check(is_string($constraint) && is_string($after[$name]));
                $this->versions->constraintChange($constraint, $after[$name], $path === 'composer.json');
            }
            unset($base->{$field}, $head->{$field});
        }
        $this->check($this->canonical($base) === $this->canonical($head));
    }

    private function composer(#[\SensitiveParameter] stdClass $lock): void
    {
        $this->keys($lock, ['_readme', 'content-hash', 'packages', 'packages-dev', 'aliases', 'minimum-stability', 'stability-flags', 'prefer-stable', 'prefer-lowest', 'platform', 'platform-dev', 'plugin-api-version']);
        $this->check(($lock->aliases ?? []) === []);
        $seen = [];
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock->{$section} ?? null;
            $this->check(is_array($packages) && array_is_list($packages));
            foreach ($packages as $package) {
                $this->check($package instanceof stdClass);
                $this->keys($package, ['name', 'version', 'source', 'dist', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'type', 'target-dir', 'autoload', 'autoload-dev', 'include-path', 'notification-url', 'license', 'authors', 'description', 'homepage', 'keywords', 'support', 'funding', 'time', 'extra', 'bin', 'abandoned', 'default-branch']);
                $this->check(is_string($package->name ?? null) && preg_match('~\A[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*\z~D', $package->name) === 1 && ! isset($seen[$package->name]) && is_string($package->version ?? null));
                $seen[$package->name] = true;
                $this->versions->lockedChange($package->version, $package->version);
                $this->check(! property_exists($package, 'notification-url') || $package->{'notification-url'} === 'https://packagist.org/downloads/');
                if (property_exists($package, 'dist')) {
                    $this->check($package->dist instanceof stdClass && in_array($package->dist->type ?? null, ['zip', 'tar'], true));
                    $this->keys($package->dist, ['url', 'type', 'reference', 'shasum']);
                    $this->url($package->dist->url ?? null, ['repo.packagist.org', 'api.github.com', 'codeload.github.com']);
                } else {
                    $this->check(($package->type ?? null) === 'metapackage');
                }
                if (property_exists($package, 'source')) {
                    $this->check($package->source instanceof stdClass && ($package->source->type ?? null) === 'git');
                    $this->keys($package->source, ['url', 'type', 'reference']);
                    $this->url($package->source->url ?? null, ['github.com']);
                }
                $trusted = $this->packagist->version($package->name, $package->version);
                foreach (['dist', 'source', 'type', 'autoload', 'extra', 'bin', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'autoload-dev', 'target-dir', 'include-path'] as $field) {
                    $this->check($this->canonical($package->{$field} ?? null) === $this->canonical($trusted->{$field} ?? null));
                }
            }
        }
    }

    private function npm(#[\SensitiveParameter] stdClass $lock): void
    {
        $this->check(in_array($lock->lockfileVersion ?? null, [2, 3], true));
        $this->references($lock);
        foreach ($this->map($lock->packages ?? null) as $location => $package) {
            $this->check($package instanceof stdClass);
            if ($location !== '') {
                $this->check(($package->link ?? false) === false);
                $this->check(is_string($location) && preg_match('~\A(?:node_modules/'.self::NpmName.'/)*node_modules/('.self::NpmName.')\z~D', $location, $match) === 1);
                $this->npmPackage($match[1], $package);
            }
        }
        if ($lock->lockfileVersion !== 3 || property_exists($lock, 'dependencies')) {
            $this->npmDependencies($lock->dependencies ?? null, $this->map($lock->packages ?? null));
        }
    }

    /** @param array<array-key,mixed> $packages */
    private function npmDependencies(#[\SensitiveParameter] mixed $dependencies, #[\SensitiveParameter] array $packages, string $parent = ''): void
    {
        foreach ($this->map($dependencies) as $name => $package) {
            $this->check($package instanceof stdClass);
            $this->check(is_string($name));
            $this->npmPackage($name, $package);
            $location = $parent.'node_modules/'.$name;
            $record = $packages[$location] ?? null;
            $this->check($record instanceof stdClass);
            foreach (['version', 'resolved', 'integrity'] as $field) {
                $this->check(($record->{$field} ?? null) === ($package->{$field} ?? null));
            }
            if (property_exists($package, 'dependencies')) {
                $this->npmDependencies($package->dependencies, $packages, $location.'/');
            }
        }
    }

    private function npmPackage(string $name, #[\SensitiveParameter] stdClass $package): void
    {
        $this->check(preg_match('~\A'.self::NpmName.'\z~D', $name) === 1 && (! property_exists($package, 'name') || $package->name === $name)
            && is_string($package->version ?? null));
        $this->versions->lockedChange($package->version, $package->version);
        $basename = basename($name);
        $expected = 'https://registry.npmjs.org/'.$name.'/-/'.$basename.'-'.$package->version.'.tgz';
        $this->check(($package->resolved ?? null) === $expected);
        $this->integrity($package->integrity ?? null);
    }

    /** pnpm accepts name@version aliases; automatic batches accept only version edges. */
    private function pnpmEdges(#[\SensitiveParameter] stdClass $lock): void
    {
        foreach ($this->map($lock->snapshots ?? new stdClass) as $snapshot) {
            $this->check($snapshot instanceof stdClass);
            foreach (['dependencies', 'optionalDependencies'] as $field) {
                foreach ($this->map($snapshot->{$field} ?? new stdClass) as $version) {
                    $this->check(is_string($version));
                    $plain = explode('(', $version)[0];
                    $this->versions->lockedChange($plain, $plain);
                }
            }
        }
    }

    private function integrity(mixed $value): void
    {
        $this->check(is_string($value) && preg_match('~\A(sha512|sha256|sha1)-([A-Za-z0-9+/]+=*)\z~D', $value, $match) === 1);
        $bytes = base64_decode($match[2], true);
        $this->check($bytes !== false && base64_encode($bytes) === $match[2] && strlen($bytes) === strlen(hash($match[1], '', true)));
    }

    /** @param list<string> $allowed */
    private function keys(#[\SensitiveParameter] stdClass $record, array $allowed): void
    {
        $this->check(array_diff(array_keys(get_object_vars($record)), $allowed) === []);
    }

    private function lockedMajors(string $path, #[\SensitiveParameter] string $base, #[\SensitiveParameter] string $head, #[\SensitiveParameter] string $manifest, ?DependencyGraph $graph): void
    {
        $root = $this->json($manifest);
        $names = [];
        $required = [];
        foreach ($path === 'composer.lock' ? ['require', 'require-dev'] : ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $field) {
            if (property_exists($root, $field)) {
                $declared = $this->map($root->{$field});
                $names += $declared;
                if (in_array($field, ['require', 'require-dev', 'dependencies', 'devDependencies'], true)) {
                    $required += $declared;
                }
            }
        }
        if ($path === 'composer.lock' && $graph !== null) {
            $names = $required = [];
            foreach ($graph->requirements as $requirement) {
                if ($requirement->from === null && $requirement->to !== null) {
                    $names[$requirement->to] = true;
                    $required[$requirement->to] = true;
                }
            }
        }
        $before = $this->lockedVersions($path, $base);
        $after = $this->lockedVersions($path, $head);
        foreach ($names as $name => $_) {
            $platform = $path === 'composer.lock' && is_string($name) && preg_match('~\A(?:php(?:-[a-z0-9]+)?|ext-[a-z0-9_-]+|lib-[a-z0-9_-]+|composer-(?:plugin|runtime)-api)\z~D', $name) === 1;
            $this->check($platform || ! array_key_exists($name, $required) || isset($after[$name]));
            if (isset($after[$name])) {
                $this->check(isset($before[$name]));
                $this->versions->lockedChange($before[$name], $after[$name]);
            }
        }
    }

    /** @return array<string,string> */
    private function lockedVersions(string $path, #[\SensitiveParameter] string $contents): array
    {
        $lock = $path === 'pnpm-lock.yaml' ? Yaml::parse($contents, Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE) : $this->json($path === 'bun.lock' ? $this->jsonc($contents) : $contents);
        $this->check($lock instanceof stdClass);
        $versions = [];
        if ($path === 'composer.lock') {
            foreach (array_merge($lock->packages ?? [], $lock->{'packages-dev'} ?? []) as $package) {
                $this->check($package instanceof stdClass && is_string($package->name ?? null) && is_string($package->version ?? null));
                $versions[$package->name] = $package->version;
            }
        } elseif ($path === 'pnpm-lock.yaml') {
            $importer = $lock->importers->{'.'} ?? null;
            foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $field) {
                foreach ($this->map($importer->{$field} ?? new stdClass) as $name => $record) {
                    $this->check(is_string($name) && $record instanceof stdClass && is_string($record->version ?? null));
                    $versions[$name] = explode('(', $record->version)[0];
                }
            }
        } elseif ($path === 'bun.lock') {
            foreach ($this->map($lock->packages ?? null) as $name => $record) {
                $this->check(is_string($name) && is_array($record) && is_string($record[0] ?? null) && preg_match(self::PackageVersion, $record[0], $identity) === 1);
                $versions[$name] = $identity[2];
            }
        } else {
            $records = ($lock->lockfileVersion ?? null) === 1 ? ($lock->dependencies ?? null) : ($lock->packages ?? null);
            foreach ($this->map($records) as $location => $record) {
                $name = $location;
                if (($lock->lockfileVersion ?? null) !== 1) {
                    if (! is_string($location) || preg_match('~\Anode_modules/((?:@[^/]+/)?[^/]+)\z~D', $location, $match) !== 1) {
                        continue;
                    }
                    $name = $match[1];
                }
                $this->check(is_string($name) && $record instanceof stdClass && is_string($record->version ?? null));
                $versions[$name] = $record->version;
            }
        }

        return $versions;
    }

    /** Reject local and Git references wherever a JS lock can carry a dependency edge. */
    private function references(#[\SensitiveParameter] mixed $value, bool $edge = false): void
    {
        if ($value instanceof stdClass || is_array($value)) {
            foreach ((array) $value as $key => $item) {
                $this->references($key);
                $this->references($item, $edge || in_array($key, ['version', 'specifier', 'resolution', 'resolved', 'tarball', 'dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies', 'registry', 'registries'], true));
            }
        } elseif (is_string($value)) {
            $this->check(preg_match('~(?:\A|@)(?:git\+|git:|ssh:|file:|link:|workspace:|github:|gitlab:|bitbucket:)~i', $value) !== 1);
            if ($edge && preg_match('~\Ahttps?://~i', $value) === 1) {
                $this->url($value, ['registry.npmjs.org']);
            }
        }
    }

    /** @param list<string> $hosts */
    private function url(#[\SensitiveParameter] mixed $value, array $hosts): void
    {
        $this->check(is_string($value));
        $url = parse_url($value);
        $this->check($url !== false && ($url['scheme'] ?? null) === 'https' && in_array($url['host'] ?? null, $hosts, true)
            && ! array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($url))
            && preg_match('/[\x00-\x20\x7f\\\\]/', $value) !== 1);
    }

    /** @return array<array-key, mixed> */
    private function map(#[\SensitiveParameter] mixed $value): array
    {
        $this->check($value instanceof stdClass);

        return get_object_vars($value);
    }

    private function canonical(#[\SensitiveParameter] mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);

            return ['object' => array_map($this->canonical(...), $properties)];
        }

        return is_array($value) ? array_map($this->canonical(...), $value) : $value;
    }

    private function json(#[\SensitiveParameter] string $value): stdClass
    {
        $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR);
        $this->check($decoded instanceof stdClass);
        // Reject duplicate keys rather than silently authorizing the last occurrence.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]]/s', $value, $tokens, PREG_OFFSET_CAPTURE);
        $objects = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if ($token === '{' || $token === '[') {
                $objects[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($objects);
            } elseif (preg_match('/\G\s*:/', $value, offset: $offset + strlen($token)) === 1) {
                $key = json_decode($token, flags: JSON_THROW_ON_ERROR);
                $this->check(is_string($key));
                $index = count($objects) - 1;
                $this->check(! isset($objects[$index][$key]));
                $objects[$index][$key] = true;
            }
        }

        return $decoded;
    }

    /** Strip JSONC comments and trailing commas while preserving strings and their URLs. */
    private function jsonc(#[\SensitiveParameter] string $value): string
    {
        $value = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|//[^\r\n]*|/\*.*?\*/~s', static fn (array $m): string => str_starts_with($m[0], '"') ? $m[0] : ' ', $value);
        $this->check($value !== null);
        $value = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|,\s*(?=[}\]])~s', static fn (array $m): string => str_starts_with($m[0], '"') ? $m[0] : '', $value);
        $this->check($value !== null);

        return $value;
    }

    private function forbidden(): never
    {
        throw new ResourceOperationException('dependency_merge.contents_forbidden', 'Only dependency constraints and approved registry sources may change.', 409);
    }

    /** @phpstan-assert true $condition */
    private function check(bool $condition): void
    {
        if (! $condition) {
            $this->forbidden();
        }
    }
}
