<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use App\Models\FirewallRule;

/**
 * The order in which UFW checks a Node's operator rules, so the first matching row wins. A rule goes at the end,
 * except that it sits above every earlier rule that is broader and overlaps it: one address above a CIDR above
 * anywhere. This is the placement the Node applies, read from the records.
 */
final class FirewallRuleOrder
{
    /**
     * @param  iterable<FirewallRule>  $rules
     * @return list<FirewallRule>
     */
    public static function sort(iterable $rules): array
    {
        $created = [...$rules];
        usort($created, static fn (FirewallRule $a, FirewallRule $b): int => $a->id <=> $b->id);
        $placed = [];

        foreach ($created as $rule) {
            $placed = self::place($placed, $rule);
        }

        return $placed;
    }

    /**
     * @param  list<FirewallRule>  $placed
     * @return list<FirewallRule>
     */
    private static function place(array $placed, FirewallRule $rule): array
    {
        $at = count($placed);

        foreach ($placed as $index => $other) {
            if (self::above($rule, $other)) {
                $at = $index;

                break;
            }
        }

        array_splice($placed, $at, 0, [$rule]);

        return $placed;
    }

    /** The rule must sit above the other: they overlap and the other rule's source is broader. */
    private static function above(FirewallRule $rule, FirewallRule $other): bool
    {
        return self::overlap($rule, $other) && self::contains($other->source, $rule->source);
    }

    private static function overlap(FirewallRule $a, FirewallRule $b): bool
    {
        if ($a->protocol !== 'any' && $b->protocol !== 'any' && $a->protocol !== $b->protocol) {
            return false;
        }

        [$aStart, $aEnd] = self::bounds($a->port);
        [$bStart, $bEnd] = self::bounds($b->port);

        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    /** @return array{int, int} */
    private static function bounds(string $port): array
    {
        $parts = array_map(intval(...), explode(':', $port));

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    /** Whether the wide source strictly contains the narrow one. */
    private static function contains(string $wide, string $narrow): bool
    {
        if ($wide === $narrow) {
            return false;
        }

        if ($wide === 'any') {
            return true;
        }

        if ($narrow === 'any') {
            return false;
        }

        [$wideAddress, $wideBits] = self::network($wide);
        [$narrowAddress, $narrowBits] = self::network($narrow);

        if ($wideAddress === null || $narrowAddress === null || strlen($wideAddress) !== strlen($narrowAddress) || $narrowBits < $wideBits) {
            return false;
        }

        $whole = intdiv($wideBits, 8);
        $rest = $wideBits % 8;

        if (substr($wideAddress, 0, $whole) !== substr($narrowAddress, 0, $whole)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($wideAddress[$whole]) & $mask) === (ord($narrowAddress[$whole]) & $mask);
    }

    /** @return array{string|null, int} */
    private static function network(string $source): array
    {
        $parts = explode('/', $source, limit: 2);
        $packed = inet_pton($parts[0]);

        return $packed === false ? [null, 0] : [$packed, isset($parts[1]) ? (int) $parts[1] : strlen($packed) * 8];
    }
}
