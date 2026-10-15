<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use Symfony\Component\Yaml\Yaml;

/** Reads registry artifacts only; local, workspace, Git and private registry packages stay cache misses. */
final readonly class NpmPackageCache
{
    /** @return list<array{manager: string, package: string, version: string, url: string, integrity: string|null, key: string}> */
    public function read(string $filename, string $contents): array
    {
        $lock = $filename === 'pnpm-lock.yaml' ? Yaml::parse($contents) : json_decode($filename === 'bun.lock' ? $this->jsonc($contents) : $contents, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($lock) && $filename === 'pnpm-lock.yaml' && ! array_key_exists('packages', $lock)
            && in_array($lock['lockfileVersion'] ?? null, ['9.0', 9.0], true) && is_array($lock['importers'] ?? null)) {
            $lock['packages'] = [];
        }
        if (! is_array($lock) || ! is_array($lock['packages'] ?? null)) {
            throw new TaskVmException('task_vm.package_cache_lock_invalid', 'JavaScript lock has no package inventory.');
        }
        $artifacts = [];
        foreach ($lock['packages'] as $location => $record) {
            if (! is_string($location) || ! is_array($record)) {
                continue;
            }
            if ($filename === 'bun.lock') {
                $identity = $record[0] ?? null;
                if (! is_string($identity) || preg_match('~\A((?:@[a-z0-9_.-]+/)?[a-z0-9][a-z0-9_.-]*)@([0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?)\z~D', $identity, $match) !== 1) {
                    continue;
                }
                $name = $match[1];
                $version = $match[2];
                $url = ($record[1] ?? '') === '' ? $this->url($name, $version) : $record[1];
                $integrity = $record[3] ?? null;
            } elseif ($filename === 'pnpm-lock.yaml') {
                if (preg_match('~\A((?:@[a-z0-9_.-]+/)?[a-z0-9][a-z0-9_.-]*)@([0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?)\z~D', $location, $match) !== 1) {
                    continue;
                }
                $name = $record['name'] ?? $match[1];
                $version = $record['version'] ?? $match[2];
                $resolution = is_array($record['resolution'] ?? null) ? $record['resolution'] : [];
                $url = $resolution['tarball'] ?? $this->url($match[1], $match[2]);
                $integrity = $resolution['integrity'] ?? null;
            } else {
                if (($record['link'] ?? false) === true || $location === '') {
                    continue;
                }
                $name = $record['name'] ?? preg_replace('~\A.*node_modules/~', '', $location);
                $version = $record['version'] ?? null;
                $url = $record['resolved'] ?? null;
                $integrity = $record['integrity'] ?? null;
            }
            if (! is_string($name) || preg_match('~\A(?:@[a-z0-9][a-z0-9_.-]*/)?[a-z0-9][a-z0-9_.-]*\z~D', $name) !== 1
                || ! is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?\z/D', $version) !== 1
                || ! is_string($url) || ! str_starts_with($url, 'https://registry.npmjs.org/')) {
                continue;
            }
            if (! is_string($integrity) || preg_match('/\A(?:sha512|sha256|sha1)-[A-Za-z0-9+\/]+=*\z/D', $integrity) !== 1) {
                continue;
            }
            if ($url !== $this->url($name, $version)) {
                continue;
            }
            $artifacts[] = ['manager' => 'npm', 'package' => $name, 'version' => $version, 'url' => $url, 'integrity' => $integrity, 'key' => 'npm/'.hash('sha256', $url.' '.$integrity).'.tgz'];
        }

        return array_values(array_column($artifacts, null, 'key'));
    }

    private function url(string $name, string $version): string
    {
        $basename = str_contains($name, '/') ? substr($name, strpos($name, '/') + 1) : $name;

        return 'https://registry.npmjs.org/'.$name.'/-/'.$basename.'-'.$version.'.tgz';
    }

    /** Remove comments and trailing commas without altering quoted strings, including URLs. */
    private function jsonc(string $contents): string
    {
        $result = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|//[^\r\n]*|/\*.*?\*/~s', static fn (array $match): string => str_starts_with($match[0], '"') ? $match[0] : ' ', $contents);
        if ($result === null) {
            throw new TaskVmException('task_vm.package_cache_lock_invalid', 'Bun text lock could not be read.');
        }
        $result = preg_replace_callback('~"(?:[^"\\\\]|\\\\.)*"|,\s*(?=[}\]])~s', static fn (array $match): string => str_starts_with($match[0], '"') ? $match[0] : '', $result);

        return $result ?? throw new TaskVmException('task_vm.package_cache_lock_invalid', 'Bun text lock could not be read.');
    }
}
