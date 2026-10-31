<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

use LogicException;

/** A checked-in, fixed repository and closed GitHub permission map. */
final readonly class GitHubTokenGrant
{
    /** @param array<string, string> $permissions */
    private function __construct(public GitHubRepository $repository, public array $permissions) {}

    public static function from(mixed $definition): self
    {
        $allowed = ['contents' => ['read', 'write'], 'pull_requests' => ['read', 'write'],
            'checks' => ['read'], 'actions' => ['read'], 'metadata' => ['read']];
        if (! is_array($definition) || array_diff(array_keys($definition), ['repository', 'permissions']) !== []
            || ! is_string($definition['repository'] ?? null)
            || ! is_array($definition['permissions'] ?? null) || $definition['permissions'] === []
            || count($definition['permissions']) > count($allowed)) {
            throw new LogicException('A GitHub grant requires a fixed repository and closed permissions.');
        }
        $repository = GitHubRepository::fromOrigin('https://github.com/'.$definition['repository']);
        if ($repository === null) {
            throw new LogicException('A GitHub grant requires a valid fixed repository.');
        }
        foreach ($definition['permissions'] as $name => $level) {
            if (! is_string($name) || ! is_string($level) || ! in_array($level, $allowed[$name] ?? [], true)) {
                throw new LogicException('A GitHub grant requires closed permissions.');
            }
        }

        return new self($repository, $definition['permissions']);
    }
}
