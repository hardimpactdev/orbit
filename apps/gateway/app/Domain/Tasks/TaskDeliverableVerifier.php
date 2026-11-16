<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Checks a subtask's deliverables against its turn receipt and matches deliverable paths
 * ([ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)).
 */
final readonly class TaskDeliverableVerifier
{
    /**
     * The deliverables a receipt must confirm and does not: every deliverable for the implementer, and every
     * `review` deliverable for the reviewer.
     *
     * @param  list<TaskDeliverable>  $deliverables
     * @param  array<string, string>  $confirmations
     * @return list<string>
     */
    public static function unconfirmed(array $deliverables, array $confirmations, TaskThreadRole $role): array
    {
        $required = array_filter($deliverables, static fn (TaskDeliverable $deliverable): bool => $role === TaskThreadRole::Implementer
            || $deliverable->type === TaskDeliverableType::Review);
        $missing = array_filter($required, static fn (TaskDeliverable $deliverable): bool => trim($confirmations[$deliverable->id] ?? '') === '');

        return array_values(array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $missing));
    }

    /**
     * Matches a path against a pattern where `*` stays within one directory, `**` crosses directories,
     * `?` is one character, and `{a,b}` is a non-nested alternative, including `{php}`.
     */
    public static function matches(string $pattern, string $path): bool
    {
        return preg_match('#\A'.self::toRegex($pattern).'\z#', $path) === 1;
    }

    private static function toRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === '{') {
                $group = self::braceAlternation($pattern, $index);
                if ($group !== null) {
                    $regex .= '(?:'.implode('|', array_map(self::toRegex(...), $group['alternatives'])).')';
                    $index = $group['end'];

                    continue;
                }
            }
            if ($character === '*' && ($pattern[$index + 1] ?? '') === '*') {
                $index++;
                if (($pattern[$index + 1] ?? '') === '/') {
                    $index++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }
            } elseif ($character === '*') {
                $regex .= '[^/]*';
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($character, '#');
            }
        }

        return $regex;
    }

    /**
     * A non-nested `{a,b,...}` group, including a single alternative such as `{php}`. Nested braces stay literal.
     *
     * @return array{end: int, alternatives: list<string>}|null
     */
    private static function braceAlternation(string $pattern, int $open): ?array
    {
        $length = strlen($pattern);
        $inside = '';
        for ($index = $open + 1; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === '{') {
                return null;
            }
            if ($character === '}') {
                return [
                    'end' => $index,
                    'alternatives' => explode(',', $inside),
                ];
            }
            $inside .= $character;
        }

        return null;
    }
}
