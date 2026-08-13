<?php

declare(strict_types=1);

namespace App\Services\Firewall;

use InvalidArgumentException;

final class IptablesRuleScript
{
    private const int LOCK_WAIT_SECONDS = 5;

    public static function ensureAppended(
        string $chain,
        string $ruleArguments,
        ?string $table = null,
    ): string {
        $prefix = self::prefix($table);

        return self::ensure(
            self::command($prefix, '-C', $chain, $ruleArguments),
            self::command($prefix, '-A', $chain, $ruleArguments),
        );
    }

    public static function ensurePrivilegedAppended(string $chain, string $ruleArguments): string
    {
        $prefix = self::privilegedPrefix('iptables');

        return self::ensure(
            self::command($prefix, '-C', $chain, $ruleArguments),
            self::command($prefix, '-A', $chain, $ruleArguments),
        );
    }

    public static function ensurePrivilegedInserted(
        string $chain,
        int $position,
        string $ruleArguments,
        string $binary = 'iptables',
    ): string {
        if ($position < 1) {
            throw new InvalidArgumentException('Iptables insert position must be positive.');
        }

        $prefix = self::privilegedPrefix($binary);

        return self::ensure(
            self::command($prefix, '-C', $chain, $ruleArguments),
            self::insertCommand($prefix, $chain, $position, $ruleArguments),
        );
    }

    public static function assertPresent(
        string $chain,
        string $ruleArguments,
        ?string $table = null,
    ): string {
        return self::command(self::prefix($table), '-C', $chain, $ruleArguments);
    }

    private static function ensure(string $check, string $mutate): string
    {
        return $check.' >/dev/null 2>&1 || '.$mutate;
    }

    private static function prefix(?string $table): string
    {
        $prefix = 'iptables -w '.self::LOCK_WAIT_SECONDS;

        if ($table === null) {
            return $prefix;
        }

        self::assertIdentifier($table, 'table');

        return $prefix.' -t '.$table;
    }

    private static function privilegedPrefix(string $binary): string
    {
        self::assertIdentifier($binary, 'binary');

        return 'sudo '.$binary.' -w '.self::LOCK_WAIT_SECONDS;
    }

    private static function command(
        string $prefix,
        string $operation,
        string $chain,
        string $ruleArguments,
    ): string {
        self::assertIdentifier($chain, 'chain');
        $ruleArguments = trim($ruleArguments);

        if ($ruleArguments === '') {
            throw new InvalidArgumentException('Iptables rule arguments are required.');
        }

        return $prefix.' '.$operation.' '.$chain.' '.$ruleArguments;
    }

    private static function insertCommand(
        string $prefix,
        string $chain,
        int $position,
        string $ruleArguments,
    ): string {
        self::assertIdentifier($chain, 'chain');
        $ruleArguments = trim($ruleArguments);

        if ($ruleArguments === '') {
            throw new InvalidArgumentException('Iptables rule arguments are required.');
        }

        return $prefix.' -I '.$chain.' '.$position.' '.$ruleArguments;
    }

    private static function assertIdentifier(string $value, string $name): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $value) === 1) {
            return;
        }

        throw new InvalidArgumentException("Iptables {$name} is invalid.");
    }
}
