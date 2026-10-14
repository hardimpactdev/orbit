<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use stdClass;

/** A closed data contract, deliberately independent of enrollment and image building. */
final readonly class RecipeLoader
{
    /** @return array<string, string> */
    public static function catalog(): array
    {
        $catalog = array_fill_keys([
            'ca-certificates', 'curl', 'gnupg', 'libnss-resolve', 'openssh-client', 'sudo', 'ufw', 'wireguard',
            'acl', 'attr', 'composer', 'docker.io', 'git', 'openssl', 'php-curl', 'php-xml', 'unzip',
            'openssh-server', 'rsync', 'bind9-dnsutils', 'gcc', 'libc6-dev',
            'libasound2t64', 'libatk-bridge2.0-0t64', 'libatk1.0-0t64', 'libatspi2.0-0t64', 'libcairo2',
            'libcups2t64', 'libdbus-1-3', 'libdrm2', 'libgbm1', 'libglib2.0-0t64', 'libnspr4', 'libnss3',
            'libpango-1.0-0', 'libx11-6', 'libxcb1', 'libxcomposite1', 'libxdamage1', 'libxext6',
            'libxfixes3', 'libxkbcommon0', 'libxrandr2',
        ], 'ubuntu');
        foreach (['caddy', 'vp', 'vp-cli', 'node', 'pnpm', 'bun', 'rust', 'cargo', 'rust-std', 'clippy', 'rustfmt', 'orbit-agent', 'libgd3'] as $name) {
            $catalog[$name] = 'artifact';
        }
        foreach (['8.5', '8.4'] as $version) {
            foreach (['cli', 'fpm', 'common', 'bcmath', 'curl', 'gd', 'imagick', 'intl', 'mbstring', 'mysql', 'pgsql', 'redis', 'sqlite3', 'xml', 'zip', 'pcov', 'readline'] as $suffix) {
                $catalog["php{$version}-{$suffix}"] = 'artifact';
            }
        }
        $catalog['php8.4-opcache'] = 'artifact';
        $catalog['pi'] = 'internal';

        return $catalog;
    }

    public function load(?string $path = null): MachineRecipe
    {
        $path ??= resource_path('machine-software/recipe.json');
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new InvalidArgumentException("Recipe [{$path}]: file is missing or unreadable.");
        }
        try {
            return $this->decode($bytes);
        } catch (InvalidArgumentException|JsonException $exception) {
            throw new InvalidArgumentException("Recipe [{$path}]: {$exception->getMessage()}", previous: $exception);
        }
    }

    public function decode(string $json): MachineRecipe
    {
        $data = json_decode($json, false, 64, JSON_THROW_ON_ERROR);
        $this->rejectDuplicateKeys($json);
        $root = $this->object($data, '$', ['schema_version', 'ubuntu', 'architectures', 'tools', 'common', 'roles', 'base']);
        $this->require($root->schema_version === 1, 'schema_version: expected integer 1.');
        $ubuntu = $this->object($root->ubuntu, 'ubuntu', ['release', 'snapshot']);
        $this->require($ubuntu->release === '26.04', 'ubuntu.release: expected supported release 26.04.');
        $snapshot = $this->text($ubuntu->snapshot, 'ubuntu.snapshot');
        $date = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', $snapshot);
        $this->require($date !== false && $date->format('Ymd\THis\Z') === $snapshot, 'ubuntu.snapshot: expected a real UTC date YYYYMMDDTHHMMSSZ.');
        $architectures = $this->strings($root->architectures, 'architectures');
        $this->require($architectures !== [] && array_diff($architectures, ['amd64', 'arm64']) === [], 'architectures: expected non-empty list of amd64 and/or arm64.');
        $rawTools = $this->object($root->tools, 'tools');
        $tools = [];
        $catalog = self::catalog();
        foreach (get_object_vars($rawTools) as $name => $raw) {
            $this->require(isset($catalog[$name]), "tools.{$name}: unknown tool.");
            $pin = $this->object($raw, "tools.{$name}");
            $source = $this->text($pin->source ?? null, "tools.{$name}.source");
            $this->require($source === $catalog[$name], "tools.{$name}.source: expected {$catalog[$name]}.");
            $this->object($pin, "tools.{$name}", match ($source) {
                'ubuntu' => ['source', 'version', 'package'],
                'internal' => ['source', 'version', 'source_commit', 'artifacts'],
                default => ['source', 'version', 'artifacts'],
            });
            $version = $this->text($pin->version, "tools.{$name}.version");
            $this->require(preg_match('/^[0-9][0-9A-Za-z.+:~_-]*$/D', $version) === 1 || ($source === 'internal' && preg_match('/^[0-9a-f]{12,40}$/D', $version) === 1), "tools.{$name}.version: expected an exact version, not a channel or constraint.");
            if ($source === 'ubuntu') {
                $this->require($pin->package === $name, "tools.{$name}.package: expected catalog package {$name}.");
                $tools[$name] = new ToolPin($source, $version, $name);

                continue;
            }
            if ($source === 'internal') {
                $commit = $this->text($pin->source_commit, "tools.{$name}.source_commit");
                $this->require(preg_match('/^[0-9a-f]{12,40}$/D', $commit) === 1, "tools.{$name}.source_commit: expected 12 to 40 lowercase hexadecimal characters.");
            }
            $rawArtifacts = $this->object($pin->artifacts, "tools.{$name}.artifacts");
            $keys = array_keys(get_object_vars($rawArtifacts));
            $this->require($keys !== [] && array_diff($keys, $architectures) === [], "tools.{$name}.artifacts: unsupported or missing architectures.");
            if ($source !== 'internal') {
                $this->require(array_diff($architectures, $keys) === [], "tools.{$name}.artifacts: every declared architecture needs a pin.");
            }
            // This seed has no Pi arm64 binary. Do not silently assign amd64 bytes to that profile.
            $this->require($name !== 'pi' || $keys === ['amd64'], 'tools.pi.artifacts: this recipe version supports Pi on amd64 only.');
            $artifacts = [];
            foreach (get_object_vars($rawArtifacts) as $architecture => $rawArtifact) {
                $field = "tools.{$name}.artifacts.{$architecture}";
                $artifact = $this->object($rawArtifact, $field);
                $algorithm = isset($artifact->sha256) ? 'sha256' : 'sha512';
                $locationKey = $source === 'internal' ? 'filename' : 'url';
                $this->object($artifact, $field, [$locationKey, $algorithm]);
                $this->require($source !== 'internal' || $algorithm === 'sha256', "{$field}: internal pins require sha256.");
                $digest = $this->text($artifact->{$algorithm}, "{$field}.{$algorithm}");
                $length = $algorithm === 'sha256' ? 64 : 128;
                $this->require(preg_match('/^[a-f0-9]{'.$length.'}$/D', $digest) === 1, "{$field}.{$algorithm}: expected {$length} lowercase hexadecimal characters.");
                $location = $this->text($artifact->{$locationKey}, "{$field}.{$locationKey}");
                if ($source === 'internal') {
                    $this->require(preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $location) === 1, "{$field}.filename: expected a simple filename, not a path.");
                } else {
                    $url = parse_url($location);
                    $this->require(is_array($url) && ($url['scheme'] ?? '') === 'https' && isset($url['host']) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['fragment']), "{$field}.url: expected an HTTPS release URL.");
                    $this->require(preg_match('~(?:latest|stable|/lts(?:/|$)|/install(?:[./]|$))~i', $location) !== 1 && preg_match('~(?:^|[/_-])v?'.preg_quote($version, '~').'(?=$|[/_]|-(?:linux|x86_64|aarch64)(?:[-./]|$)|\\.tar\\.|\\.tgz$|\\.zip$|\\.deb$)~D', rawurldecode($location)) === 1, "{$field}.url: expected the exact version in an immutable release URL, not an installer/channel.");
                }
                $artifacts[$architecture] = new ArtifactPin($location, $algorithm, $digest);
            }
            $tools[$name] = new ToolPin($source, $version, artifacts: $artifacts);
        }
        $this->require(array_diff(array_keys($catalog), array_keys($tools)) === [], 'tools: missing catalog tools: '.implode(', ', array_diff(array_keys($catalog), array_keys($tools))));
        $common = $this->references($root->common, 'common', $tools);
        $roles = $this->object($root->roles, 'roles', ['gateway', 'app-dev', 'app-prod', 'operator']);
        $profiles = [];
        $used = $common;
        foreach ([...get_object_vars($roles), 'base' => $root->base] as $role => $raw) {
            $extra = $this->references($raw, "profiles.{$role}", $tools);
            $this->require(array_intersect($common, $extra) === [], "profiles.{$role}: repeats a common tool.");
            $used = [...$used, ...$extra];
            $effective = [...$common, ...$extra];
            sort($effective, SORT_STRING);
            $profiles[$role] = $effective;
        }
        $this->require(array_diff(array_keys($tools), $used) === [], 'tools: unused tool entries.');
        // Locations of internal bytes are deliberately not software identity.
        $identityData = $root;
        foreach ($identityData->tools as $pin) {
            if ($pin->source === 'internal') {
                foreach ($pin->artifacts as $artifact) {
                    unset($artifact->filename);
                }
            }
        }
        $canonical = json_encode($this->canonical($identityData), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return new MachineRecipe('sha256:'.hash('sha256', $canonical), $snapshot, $architectures, $tools, $profiles, $canonical, $json);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }

    /** @param ?list<string> $keys */
    private function object(mixed $value, string $field, ?array $keys = null): stdClass
    {
        $this->require($value instanceof stdClass, "{$field}: expected an object.");
        if (! $value instanceof stdClass) {
            throw new InvalidArgumentException("{$field}: expected an object.");
        }
        if ($keys !== null) {
            $actual = array_keys(get_object_vars($value));
            $this->require(array_diff($keys, $actual) === [] && array_diff($actual, $keys) === [], "{$field}: expected exactly fields ".implode(', ', $keys).'; got '.implode(', ', $actual).'.');
        }

        return $value;
    }

    private function text(mixed $value, string $field): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("{$field}: expected a non-empty string.");
        }

        return $value;
    }

    /** @return list<string> */
    private function strings(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$field}: expected a list.");
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = $this->text($item, $field);
        }
        $this->require(count(array_unique($result)) === count($result), "{$field}: duplicate list entry.");

        return $result;
    }

    /** @param array<string, ToolPin> $tools
     * @return list<string>
     */
    private function references(mixed $value, string $field, array $tools): array
    {
        $names = $this->strings($value, $field);
        foreach ($names as $name) {
            $this->require(isset($tools[$name]), "{$field}: unknown or missing tool [{$name}].");
        }

        return $names;
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $result = new stdClass;
            foreach ($properties as $key => $property) {
                $result->{$key} = $this->canonical($property);
            }

            return $result;
        }
        if (is_array($value)) {
            return array_map($this->canonical(...), $value);
        }
        $this->require(! is_float($value), 'Floating-point numbers are not allowed.');

        return $value;
    }

    /** json_decode accepts duplicate keys, so scan the already syntax-checked tokens before using its result. */
    private function rejectDuplicateKeys(string $json): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\\[\\]:,]/s', $json, $matches);
        $stack = [];
        $tokens = $matches[0];
        foreach ($tokens as $index => $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = [];
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif (str_starts_with($token, '"') && ($tokens[$index + 1] ?? '') === ':') {
                $key = $this->text(json_decode($token, true, flags: JSON_THROW_ON_ERROR), 'object key');
                $last = count($stack) - 1;
                $this->require(! isset($stack[$last][$key]), "Duplicate object key [{$key}].");
                $stack[$last][$key] = true;
            }
        }
    }
}
