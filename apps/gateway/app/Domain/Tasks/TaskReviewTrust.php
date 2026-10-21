<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;

/** Operator-owned repair authority. Display names and repository roles are never consulted. */
final readonly class TaskReviewTrust
{
    /** @param list<int> $accountIds */
    private function __construct(public bool $valid, public array $accountIds, public string $revision) {}

    /** The trust that the operator's value `owner/repo:id,id;owner/repo:id` grants on $repository. */
    public static function fromConfig(GitHubRepository $repository, string $value): self
    {
        $key = strtolower($repository->owner.'/'.$repository->name);
        $config = self::parse($value);
        // Invalid aliases and wildcard scopes disable only repositories they could name.
        // Unrelated malformed keys cannot revoke a valid repository's operator-owned trust.
        foreach (array_keys($config) as $scope) {
            if (! is_string($scope) || $scope === $key) {
                continue;
            }
            $alias = GitHubRepository::fromOrigin($scope)
                ?? GitHubRepository::fromOrigin('https://github.com/'.$scope);
            $aliasKey = $alias === null ? null : strtolower($alias->owner.'/'.$alias->name);
            if ($aliasKey === $key || fnmatch($scope, $key)) {
                return self::invalid($key);
            }
        }
        $ids = $config[$key] ?? [];
        foreach ($ids as $id) {
            if (! is_int($id)) {
                return self::invalid($key);
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return new self(true, $ids, hash('sha256', serialize([$key, $ids])));
    }

    /**
     * Reads the operator's value into lower-case repository => account IDs. A token that is not a positive integer
     * stays a string, so fromConfig() disables that repository instead of trusting it.
     *
     * @return array<array-key, list<int|string>>
     */
    private static function parse(string $value): array
    {
        $config = [];
        foreach (explode(';', $value) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$repository, $ids] = array_pad(explode(':', $entry, 2), 2, '');
            $config[strtolower(trim($repository))] = array_map(
                static fn (string $id): int|string => preg_match('/\A[1-9][0-9]{0,18}\z/', $id) === 1 ? (int) $id : $id,
                array_map(trim(...), explode(',', $ids)),
            );
        }

        return $config;
    }

    private static function invalid(string $key): self
    {
        return new self(false, [], hash('sha256', $key.':invalid'));
    }
}
