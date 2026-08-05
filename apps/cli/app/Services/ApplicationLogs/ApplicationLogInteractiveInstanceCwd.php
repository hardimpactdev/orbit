<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogInteractiveInstanceCwd
{
    /**
     * @param  array<string, mixed>  $inferred
     * @return array{ok: true, selector: string}|array{ok: false, message: string, reason: string}
     */
    public function normalize(array $inferred): array
    {
        if (isset($inferred['error'])) {
            return [
                'ok' => false,
                'message' => (string) $inferred['error'],
                'reason' => is_string($inferred['reason'] ?? null) ? $inferred['reason'] : 'cwd_target_missing',
            ];
        }

        $selector = $inferred['selector'] ?? null;

        if (! is_string($selector) || $selector === '') {
            return [
                'ok' => false,
                'message' => 'No unambiguous instance target could be inferred from the current directory.',
                'reason' => 'cwd_target_missing',
            ];
        }

        return [
            'ok' => true,
            'selector' => $selector,
        ];
    }
}
