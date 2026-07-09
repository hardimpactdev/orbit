<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use JsonException;

/** @mago-expect lint:cyclomatic-complexity */
final class CodexWorktreeNameResolver
{
    public function resolve(string $path): ?string
    {
        $key = $this->worktreeKey($path);

        if ($key === null) {
            return null;
        }

        $gitDirectory = $this->gitDirectory($path);

        if ($gitDirectory === null) {
            return null;
        }

        $branch = $this->metadata("{$gitDirectory}/codex-synced-branch.json");

        if (is_string($branch['branch'] ?? null) && str_starts_with($branch['branch'], 'refs/heads/')) {
            $name = $this->slug(substr($branch['branch'], strlen('refs/heads/')));

            if ($name !== null) {
                return $name;
            }
        }

        $thread = $this->metadata("{$gitDirectory}/codex-thread.json");

        if (! is_array($thread)) {
            return null;
        }

        return $this->slug("codex-{$key}");
    }

    private function worktreeKey(string $path): ?string
    {
        $normalized = rtrim(string: $path, characters: '/');
        $matches = [];

        if (! preg_match('#(?:^|/)\.codex/worktrees/([a-z0-9]+)/[^/]+$#i', $normalized, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function gitDirectory(string $path): ?string
    {
        $gitFile = "{$path}/.git";

        if (! is_file($gitFile)) {
            return null;
        }

        $contents = file_get_contents($gitFile);

        if (! is_string($contents)) {
            return null;
        }

        $matches = [];

        if (! preg_match('/^gitdir: (.+)$/m', $contents, $matches)) {
            return null;
        }

        $gitDirectory = trim($matches[1]);

        if ($gitDirectory === '') {
            return null;
        }

        if (! str_starts_with($gitDirectory, '/')) {
            return "{$path}/{$gitDirectory}";
        }

        return $gitDirectory;
    }

    /** @return array<string, mixed>|null */
    private function metadata(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            return null;
        }

        try {
            $metadata = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($metadata) || array_is_list($metadata)) {
            return null;
        }

        /** @var array<string, mixed> $metadata */
        return $metadata;
    }

    private function slug(string $value): ?string
    {
        $slug = strtolower($value);
        $slug = preg_replace(pattern: '/[^a-z0-9]+/', replacement: '-', subject: $slug);

        if (! is_string($slug)) {
            return null;
        }

        $slug = trim(string: $slug, characters: '-');

        if ($slug === '') {
            return null;
        }

        if (strlen($slug) <= 63) {
            return $slug;
        }

        return (
            substr(string: $slug, offset: 0, length: 54)
            .'-'
            .substr(string: hash(algo: 'sha256', data: $slug), offset: 0, length: 8)
        );
    }
}
