<?php

declare(strict_types=1);

/**
 * Shared worker-identity matching for archive and capture.
 *
 * Standalone identity is a line-start `Orbit worker: <id>` (the spawn bootstrap
 * may continue on the same line). Mid-line mentions are not primary.
 */

/** @return list<string> */
function workerIdsFromText(string $text): array
{
    if (preg_match_all('/Orbit worker:\s*([a-z][a-z0-9-]*)\b/', $text, $matches) === false) {
        return [];
    }

    return array_values(array_unique($matches[1]));
}

/** @return list<string> */
function standaloneWorkerIdsFromIdentityText(string $text): array
{
    if (preg_match_all('/(?:^|\R)\h*Orbit worker:\h*([a-z][a-z0-9-]*)\b/', $text, $matches) === false) {
        return [];
    }

    return array_values(array_unique($matches[1]));
}

function textHasWorkerId(string $text, string $workerId): bool
{
    return in_array($workerId, workerIdsFromText($text), true);
}

function orbitWorkerExtractText(mixed $content): string
{
    if (is_string($content)) {
        return $content;
    }

    if (! is_array($content)) {
        return '';
    }

    $parts = [];

    foreach ($content as $part) {
        if (is_array($part) && isset($part['text'])) {
            $parts[] = (string) $part['text'];
        }
    }

    return implode("\n", $parts);
}

/**
 * @param list<array{path: string, primary_worker_id: ?string, mentions: bool}> $candidates
 * @return array{
 *     status: 'ok'|'partial'|'ambiguous'|'missing',
 *     reason: ?string,
 *     chosen: ?array<string, mixed>,
 * }
 */
function orbitWorkerSelectOwnedCandidates(array $candidates, string $workerId): array
{
    $full = [];
    $partial = [];

    foreach ($candidates as $candidate) {
        if (($candidate['primary_worker_id'] ?? null) === $workerId) {
            $full[] = $candidate;

            continue;
        }

        if (($candidate['mentions'] ?? false) === true && ($candidate['primary_worker_id'] ?? null) === null) {
            $partial[] = $candidate;
        }
    }

    if (count($full) === 1) {
        return ['status' => 'ok', 'reason' => null, 'chosen' => $full[0]];
    }

    if (count($full) > 1) {
        return ['status' => 'ambiguous', 'reason' => 'ambiguous_duplicate_markers', 'chosen' => null];
    }

    if (count($partial) === 1) {
        return ['status' => 'partial', 'reason' => 'missing_primary_identity', 'chosen' => $partial[0]];
    }

    if (count($partial) > 1) {
        return ['status' => 'ambiguous', 'reason' => 'ambiguous_duplicate_markers', 'chosen' => null];
    }

    return ['status' => 'missing', 'reason' => 'no_owned_marker_transcript', 'chosen' => null];
}
