<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

/**
 * Derives MySQL database and user names from Orbit names. Hyphens and other characters outside
 * `[a-z0-9_]` become underscores. A name over the MySQL limit keeps a prefix and ends in an
 * underscore and the first 8 characters of the SHA-1 of the full name, so it stays unique and
 * the same input always gives the same name. A database name is also a grant: MySQL measures it
 * after each underscore is escaped, and a test prefix grant adds `%`.
 */
final readonly class MysqlNames
{
    public const int DATABASE_MAX_LENGTH = 64;

    public const int USER_MAX_LENGTH = 32;

    private const int HASH_LENGTH = 8;

    public function database(string $name): string
    {
        return $this->limit($this->normalize($name), self::DATABASE_MAX_LENGTH, grant: 0);
    }

    public function testDatabase(string $database): string
    {
        return $this->limit($database.'_test', self::DATABASE_MAX_LENGTH, grant: 1);
    }

    public function user(string $name): string
    {
        return $this->limit($this->normalize($name), self::USER_MAX_LENGTH);
    }

    public function instanceUser(string $projectSlug, string $instanceName): string
    {
        return $this->user($projectSlug.'_'.$instanceName);
    }

    private function normalize(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9_]/', '_', strtolower($name));
    }

    /** @param  ?int  $grant  null for a user name; else the wildcard length its escaped grant adds */
    private function limit(string $name, int $max, ?int $grant = null): string
    {
        $length = static fn (string $value): int => strlen($value) + ($grant === null ? 0 : substr_count($value, '_') + $grant);
        if ($length($name) <= $max) {
            return $name;
        }

        $suffix = '_'.substr(sha1($name), 0, self::HASH_LENGTH);
        $prefix = substr($name, 0, $max - strlen($suffix));
        while ($length($prefix.$suffix) > $max) {
            $prefix = substr($prefix, 0, -1);
        }

        return $prefix.$suffix;
    }
}
