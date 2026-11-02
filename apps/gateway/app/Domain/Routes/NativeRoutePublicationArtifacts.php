<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Support\ValidatedData;
use stdClass;

/** Changed publication entries only, with equal artifacts shared by every native phase. */
final readonly class NativeRoutePublicationArtifacts
{
    /** @param list<array<string,mixed>> $publications
     * @return array{publications:list<array<string,mixed>>,artifacts:array<string,list<array<string,mixed>>>}
     */
    public function capture(array $publications): array
    {
        $artifacts = ['caddy' => [], 'php' => [], 'dns' => []];
        $indexes = [];
        foreach ($publications as &$publication) {
            $states = [];
            foreach (['before', 'prepared', 'withdrawn', 'after'] as $name) {
                $states[$name] = ValidatedData::object($publication[$name]);
            }
            foreach ($this->scope($states) as $name => $state) {
                $publication[$name] = [];
                foreach (['caddy', 'php', 'dns'] as $kind) {
                    if ($state[$kind] === null) {
                        $publication[$name][$kind] = null;

                        continue;
                    }
                    $artifact = ValidatedData::object($state[$kind]);
                    $publication[$name][$kind] = $this->intern($artifacts, $kind, $artifact, $indexes);
                }
            }
        }
        unset($publication);

        return ['publications' => $publications, 'artifacts' => $artifacts];
    }

    /** @param array<string,list<array<string,mixed>>> $artifacts
     * @param  array<string,mixed>  $artifact
     * @param  array<string,array<string,int>>  $indexes
     */
    public function intern(array &$artifacts, string $kind, array $artifact, array &$indexes): int
    {
        if (! isset($indexes[$kind])) {
            $indexes[$kind] = [];
            foreach ($artifacts[$kind] as $index => $existing) {
                $indexes[$kind][hash('sha256', json_encode($existing, JSON_THROW_ON_ERROR))] = $index;
            }
        }
        $hash = hash('sha256', json_encode($artifact, JSON_THROW_ON_ERROR));
        if (isset($indexes[$kind][$hash])) {
            return $indexes[$kind][$hash];
        }
        $index = count($artifacts[$kind]);
        $artifacts[$kind][] = $artifact;
        $indexes[$kind][$hash] = $index;

        return $index;
    }

    /** Local render consumers resolve references without expanding the dispatched intent.
     * @param  array<string,mixed>  $artifacts
     * @param  array<string,mixed>  $publication
     * @return array<string,mixed>
     */
    public static function resolve(array $artifacts, array $publication): array
    {
        foreach (['before', 'prepared', 'withdrawn', 'after'] as $name) {
            $state = ValidatedData::object($publication[$name]);
            foreach (['caddy', 'php', 'dns'] as $kind) {
                $state[$kind] = $state[$kind] === null ? null : RouteExecutionProof::objects($artifacts[$kind])[ValidatedData::integer($state[$kind])];
            }
            $publication[$name] = $state;
        }

        return $publication;
    }

    /** @param array<string,array<string,mixed>> $states
     * @return array<string,array<string,mixed>>
     */
    public function scope(array $states): array
    {
        $caddy = $php = $records = $catalogs = [];
        foreach ($states as $name => $state) {
            $caddy[$name] = $this->caddyGroups(ValidatedData::object($state['caddy']));
            $php[$name] = $this->phpGroups(ValidatedData::object($state['php']));
            $dns = $state['dns'] === null ? null : ValidatedData::object($state['dns']);
            $records[$name] = $dns === null ? [] : $this->records(ValidatedData::string($dns['records']));
            $catalogs[] = $dns === null ? new stdClass : json_decode(ValidatedData::string($dns['catalog']), false, 512, JSON_THROW_ON_ERROR);
        }
        $caddyKeys = $this->changedKeys(array_values($caddy));
        $phpKeys = $this->changedKeys(array_values($php));
        $dnsKeys = $this->changedKeys(array_values($records));
        $missing = new stdClass;
        $catalogs = $this->scopeValues($catalogs, $missing);
        foreach (array_keys($states) as $index => $name) {
            $state = &$states[$name];
            $input = ValidatedData::object($state['caddy']);
            $state['caddy'] = $this->caddySubset($input, $caddyKeys);
            $input = ValidatedData::object($state['php']);
            $configurations = [];
            foreach ($this->configurations($input['configurations']) as $version => $text) {
                $sections = $this->phpSections(ValidatedData::string($text));
                $groups = array_filter($sections['groups'], static fn (string $pool): bool => in_array($version."\0".$pool, $phpKeys, true), ARRAY_FILTER_USE_KEY);
                if ($groups !== []) {
                    $configurations[$version] = $sections['prefix'].implode('', $groups);
                }
            }
            $state['php'] = [...$input, 'configurations' => (object) $configurations];
            if ($state['dns'] !== null) {
                $dns = ValidatedData::object($state['dns']);
                $lines = array_intersect_key($records[$name], array_fill_keys($dnsKeys, true));
                $catalog = json_encode($catalogs[$index] === $missing ? new stdClass : $catalogs[$index], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                $state['dns'] = [...$dns, 'records' => "# Managed by Orbit.\n".implode("\n", $lines)."\n", 'catalog' => $catalog, 'digest' => hash('sha256', $catalog)];
            }
            unset($state['certificate_scopes']);
        }
        unset($state);

        return $states;
    }

    /** @param array<string,mixed> $caddy
     * @return array<string,list<string>>
     */
    private function caddyGroups(array $caddy): array
    {
        $content = ValidatedData::string($caddy['content']);
        preg_match_all('/^# orbit: ([^\\s]+) ([^\\r\\n]+)\\n/m', $content, $matches, PREG_OFFSET_CAPTURE);
        $groups = [];
        foreach ($matches[0] as $index => [$marker, $offset]) {
            $key = $matches[1][$index][0]."\0".$matches[2][$index][0];
            $groups[$key][] = rtrim(substr($content, $offset, ($matches[0][$index + 1][1] ?? strlen($content)) - $offset), "\n")."\n";
        }

        return $groups;
    }

    /** Restrict an accepted alternate to the same affected groups as a preparation delta.
     * @param  array<string,mixed>  $caddy
     * @param  list<array<string,mixed>>  $references
     * @return array<string,mixed>
     */
    public function restrictCaddy(array $caddy, array $references): array
    {
        $keys = array_values(array_unique(array_merge(...array_map(fn (array $reference): array => array_keys($this->caddyGroups($reference)), $references))));

        return $this->caddySubset($caddy, $keys);
    }

    /** Sparse unserved means no affected blocks; had_served_groups describes the full source snapshot.
     * @param  array<string,mixed>  $caddy
     * @param  list<string>  $keys
     * @return array<string,mixed>
     */
    private function caddySubset(array $caddy, array $keys): array
    {
        $text = ValidatedData::string($caddy['content']);
        preg_match('/^# orbit: [^\\s]+ [^\\r\\n]+\\n/m', $text, $marker, PREG_OFFSET_CAPTURE);
        $header = rtrim(substr($text, 0, $marker[0][1] ?? strlen($text)), "\n")."\n";
        $allGroups = $this->caddyGroups($caddy);
        $hadServedGroups = $caddy['had_served_groups'] ?? $allGroups !== [];
        $groups = array_intersect_key($allGroups, array_fill_keys($keys, true));
        $blocks = array_merge([], ...array_values($groups));
        $content = $header.($blocks === [] ? '' : "\n".implode("\n", $blocks));
        unset($caddy['blocks']);

        return [...$caddy, 'content' => $content, 'version' => substr(hash('sha256', $content), 0, 32), 'unserved' => $blocks === [], 'had_served_groups' => $hadServedGroups];
    }

    /** @param array<string,mixed> $php
     * @return array<string,string>
     */
    private function phpGroups(array $php): array
    {
        $groups = [];
        foreach ($this->configurations($php['configurations']) as $version => $text) {
            foreach ($this->phpSections(ValidatedData::string($text))['groups'] as $name => $section) {
                $groups[$version."\0".$name] = $section;
            }
        }

        return $groups;
    }

    /** @return array<string,string> */
    private function configurations(mixed $value): array
    {
        if (! $value instanceof stdClass) {
            throw new \LogicException('Shared PHP capture requires a configuration object.');
        }

        return ValidatedData::stringMap(get_object_vars($value));
    }

    /** @return array{prefix:string,groups:array<string,string>} */
    private function phpSections(string $text): array
    {
        preg_match_all('/^\[([^\]\r\n]+)\]\r?$/m', $text, $matches, PREG_OFFSET_CAPTURE);
        $prefix = substr($text, 0, $matches[0][0][1] ?? strlen($text));
        $groups = [];
        foreach ($matches[1] as $index => [$name]) {
            $offset = $matches[0][$index][1];
            $groups[$name] = substr($text, $offset, ($matches[0][$index + 1][1] ?? strlen($text)) - $offset);
        }

        return ['prefix' => $prefix, 'groups' => $groups];
    }

    /** @return array<string,string> */
    private function records(string $text): array
    {
        $records = [];
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^(host-record)=([^,]+),.+$|^(address|local)=\/([^\/]+)\//', $line, $match) === 1) {
                $records[($match[1] !== '' ? $match[1].':'.$match[2] : $match[3].':'.$match[4])] = $line;
            }
        }

        return $records;
    }

    /** @param list<array<string,mixed>> $maps
     * @return list<string>
     */
    private function changedKeys(array $maps): array
    {
        $keys = array_values(array_unique(array_merge(...array_map(array_keys(...), $maps))));

        return array_values(array_filter($keys, static function (string $key) use ($maps): bool {
            $values = array_map(static fn (array $map): ?string => array_key_exists($key, $map) ? json_encode($map[$key], JSON_THROW_ON_ERROR) : null, $maps);

            return count(array_unique($values, SORT_REGULAR)) > 1;
        }));
    }

    /** @param list<mixed> $values
     * @return list<mixed>
     */
    private function scopeValues(array $values, stdClass $missing): array
    {
        $encoded = array_map(static fn (mixed $value): ?string => $value === $missing ? null : json_encode($value, JSON_THROW_ON_ERROR), $values);
        if (count(array_unique($encoded, SORT_REGULAR)) === 1) {
            return array_fill(0, count($values), $missing);
        }
        if (! array_all($values, static fn (mixed $value): bool => $value === $missing || $value instanceof stdClass || $value === [])) {
            return $values;
        }
        $objects = array_map(static fn (mixed $value): array => $value instanceof stdClass && $value !== $missing ? get_object_vars($value) : [], $values);
        $result = array_map(static fn (): stdClass => new stdClass, $values);
        foreach (array_unique(array_merge(...array_map(array_keys(...), $objects))) as $key) {
            $children = $this->scopeValues(array_map(static fn (array $value): mixed => array_key_exists($key, $value) ? $value[$key] : $missing, $objects), $missing);
            foreach ($children as $index => $child) {
                if ($child !== $missing) {
                    $result[$index]->{$key} = $child;
                }
            }
        }

        return $result;
    }
}
