<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use JsonException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** A path allowlist cannot authorize changes to build, test or acquisition instructions. */
final readonly class DependencyBatchContents
{
    public const array Files = ['composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock', 'bun.lock'];

    private const string PackageVersion = '~\A(?:@[a-z0-9_.-]+/)?[a-z0-9_.-]+@[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?\z~D';

    public function validate(string $path, #[\SensitiveParameter] string $head, #[\SensitiveParameter] ?string $base = null): void
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
                foreach ($this->map($lock->packages ?? null) as $id => $package) {
                    $this->check(is_string($id) && preg_match(self::PackageVersion, $id) === 1 && $package instanceof stdClass);
                    $resolution = $package->resolution ?? null;
                    $this->check($resolution instanceof stdClass);
                    if (property_exists($resolution, 'tarball')) {
                        $this->url($resolution->tarball, ['registry.npmjs.org']);
                    } else {
                        $this->check(is_string($resolution->integrity ?? null));
                    }
                    $this->check(! property_exists($resolution, 'repo') && ! property_exists($resolution, 'directory') && ! property_exists($resolution, 'type'));
                }
            } elseif ($path === 'bun.lock') {
                $lock = $this->json($this->jsonc($head));
                $this->check(in_array($lock->lockfileVersion ?? null, [1, 2], true));
                $this->references($lock);
                foreach ($this->map($lock->packages ?? null) as $package) {
                    $this->check(is_array($package) && array_is_list($package) && count($package) === 4
                        && is_string($package[0]) && preg_match(self::PackageVersion, $package[0]) === 1 && is_string($package[1]));
                    if ($package[1] !== '') {
                        $this->url($package[1], ['registry.npmjs.org']);
                    }
                }
            } else {
                $this->forbidden();
            }
        } catch (JsonException|ParseException) {
            throw new ResourceOperationException('dependency_merge.contents_unreadable', 'Dependency content could not be decoded.', 409);
        }
    }

    private function manifest(string $path, #[\SensitiveParameter] stdClass $base, #[\SensitiveParameter] stdClass $head): void
    {
        $fields = $path === 'composer.json' ? ['require', 'require-dev'] : ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'];
        foreach ([$base, $head] as $manifest) {
            foreach ($fields as $field) {
                if (property_exists($manifest, $field)) {
                    foreach ($this->map($manifest->{$field}) as $constraint) {
                        $this->check(is_string($constraint));
                    }
                    unset($manifest->{$field});
                }
            }
        }
        $this->check($this->canonical($base) === $this->canonical($head));
    }

    private function composer(#[\SensitiveParameter] stdClass $lock): void
    {
        foreach (['packages', 'packages-dev'] as $section) {
            $packages = $lock->{$section} ?? null;
            $this->check(is_array($packages) && array_is_list($packages));
            foreach ($packages as $package) {
                $this->check($package instanceof stdClass);
                if (property_exists($package, 'dist')) {
                    $this->check($package->dist instanceof stdClass && in_array($package->dist->type ?? null, ['zip', 'tar'], true));
                    $this->url($package->dist->url ?? null, ['repo.packagist.org', 'api.github.com', 'codeload.github.com']);
                } else {
                    $this->check(($package->type ?? null) === 'metapackage');
                }
                if (property_exists($package, 'source')) {
                    $this->check($package->source instanceof stdClass && ($package->source->type ?? null) === 'git');
                    $this->url($package->source->url ?? null, ['github.com']);
                }
            }
        }
    }

    private function npm(#[\SensitiveParameter] stdClass $lock): void
    {
        $this->check(in_array($lock->lockfileVersion ?? null, [1, 2, 3], true));
        $this->references($lock);
        if ($lock->lockfileVersion !== 1) {
            foreach ($this->map($lock->packages ?? null) as $location => $package) {
                $this->check($package instanceof stdClass);
                if ($location !== '') {
                    $this->check(($package->link ?? false) === false);
                    $this->url($package->resolved ?? null, ['registry.npmjs.org']);
                }
            }
        }
        if ($lock->lockfileVersion !== 3 || property_exists($lock, 'dependencies')) {
            $this->npmDependencies($lock->dependencies ?? null);
        }
    }

    private function npmDependencies(#[\SensitiveParameter] mixed $dependencies): void
    {
        foreach ($this->map($dependencies) as $package) {
            $this->check($package instanceof stdClass);
            $this->url($package->resolved ?? null, ['registry.npmjs.org']);
            if (property_exists($package, 'dependencies')) {
                $this->npmDependencies($package->dependencies);
            }
        }
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
