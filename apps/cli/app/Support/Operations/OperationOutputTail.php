<?php

declare(strict_types=1);

namespace App\Support\Operations;

/**
 * Turns the output tail that each read returns into the new text since the last read of the same step. The Gateway
 * keeps only the last 8 KiB, so when the tail moved past what was printed, the new tail prints whole after a marker.
 */
final class OperationOutputTail
{
    private const int Anchor = 256;

    /** @var array<string, string> */
    private array $printed = [];

    /** @return list<string> the new lines of this step */
    public function lines(string $step, ?string $tail): array
    {
        if ($tail === null || $tail === '') {
            return [];
        }

        $before = $this->printed[$step] ?? '';
        $this->printed[$step] = $tail;

        if (str_starts_with($tail, $before)) {
            $new = substr($tail, strlen($before));
        } else {
            $anchor = substr($before, -self::Anchor);
            $at = $anchor === '' ? false : strrpos($tail, $anchor);
            $new = $at === false ? "[orbit] earlier output skipped\n".$tail : substr($tail, $at + strlen($anchor));
        }

        return $new === '' ? [] : array_values(array_filter(explode("\n", rtrim($new, "\n")), static fn (string $line): bool => $line !== ''));
    }
}
