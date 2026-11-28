<?php

declare(strict_types=1);

namespace App\Domain\Routes;

/**
 * A shared PHP-FPM pool file with a withdrawal applied scope by scope, as `route.withdraw_site`'s `merge_pool` does today
 * (apps/agent/crates/route/src/withdraw.rs): the Gateway renders the whole file it sends to `config.apply`.
 *
 * A pool render holds `__ORBIT_NODE_GROUP__` where the account's primary group goes. The Node writes it as the gid, or as the
 * group's name, which an older publisher wrote in its place; `$forms` lists both, the gid first.
 */
final class SharedPoolMerge
{
    public const string Group = '__ORBIT_NODE_GROUP__';

    /**
     * The live pool with the withdrawal applied: each scope the render changes must still hold its rendered-from or desired
     * state, its group in one of `$forms`, else null (a change outside this Route); it then takes the desired state with the
     * first form. Every other scope, and the text before the first scope, keeps its live bytes, also its absence.
     *
     * @param  non-empty-list<string>  $forms
     */
    public static function merge(string $from, string $desired, string $live, array $forms): ?string
    {
        [, $original] = self::sections($from);
        [, $wanted] = self::sections($desired);
        [$prefix, $observed] = self::sections($live);
        $matches = static fn (string $live, ?string $text): bool => $text !== null
            && array_filter($forms, static fn (string $form): bool => self::bare(self::resolve($text, $form)) === self::bare($live)) !== [];
        $out = $observed;
        foreach (array_unique([...array_keys($original), ...array_keys($wanted)]) as $name) {
            $name = (string) $name;
            $before = $original[$name] ?? null;
            $after = $wanted[$name] ?? null;
            if (($before === null ? null : self::bare($before)) === ($after === null ? null : self::bare($after))) {
                continue;
            }
            $current = $observed[$name] ?? null;
            if ($current !== null) {
                if (! $matches($current, $before) && ! $matches($current, $after)) {
                    return null;
                }
                if ($matches($current, $after)) {
                    continue;
                }
            }
            if ($after === null) {
                unset($out[$name]);
            } else {
                $out[$name] = self::resolve($after, $forms[0]);
            }
        }
        // The executor kept scopes in name order (a BTreeMap): the same bytes for the same scopes.
        ksort($out, SORT_STRING);

        return $prefix.implode('', $out);
    }

    /** The pool text with the account's group in `$form`. */
    public static function resolve(string $text, string $form): string
    {
        return str_replace(self::Group, $form, $text);
    }

    /**
     * A pool file as its text before the first scope and each `[scope]` section by name.
     *
     * @return array{string, array<string, string>}
     */
    private static function sections(string $text): array
    {
        $prefix = '';
        $scopes = [];
        $current = null;
        foreach (preg_split('/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
            $trimmed = trim($line);
            if (strlen($trimmed) >= 2 && str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
                $current = substr($trimmed, 1, -1);
                $scopes[$current] = $line;
            } elseif ($current !== null) {
                $scopes[$current] .= $line;
            } else {
                $prefix .= $line;
            }
        }

        return [$prefix, $scopes];
    }

    /** A scope without the blank lines that separate it from the next: removing a scope changes only its neighbour's separator. */
    private static function bare(string $text): string
    {
        return rtrim($text, "\n \t");
    }
}
