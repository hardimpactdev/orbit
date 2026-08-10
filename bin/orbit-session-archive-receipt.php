<?php

declare(strict_types=1);

function archive_receipt_mode(string $archiveDir): ?string
{
    $receiptPath = $archiveDir.'/orbit-session-archive.json';

    if (! file_exists($receiptPath) && ! is_link($receiptPath)) {
        return null;
    }

    if (! is_file($receiptPath) || is_link($receiptPath)) {
        return 'invalid';
    }

    $receipt = json_decode((string) file_get_contents($receiptPath), true);
    $mode = is_array($receipt) ? $receipt['archive_mode'] ?? null : null;

    return in_array($mode, ['compact', 'full'], true) ? $mode : 'invalid';
}

function compact_archive_receipt_is_valid(string $archiveDir, string $root, string $branch): bool
{
    $receiptPath = $archiveDir.'/orbit-session-archive.json';

    if (! is_file($receiptPath) || is_link($receiptPath)) {
        return false;
    }

    $receipt = json_decode((string) file_get_contents($receiptPath), true);
    $schemaVersion = is_array($receipt) ? ($receipt['schema_version'] ?? null) : null;

    if (
        ! is_array($receipt)
        || ! in_array($schemaVersion, [2, 3], true)
        || ($receipt['archive_mode'] ?? null) !== 'compact'
        || ($receipt['branch'] ?? null) !== $branch
    ) {
        return false;
    }

    $branchTipResult = run_git($root, ['rev-parse', '--verify', "refs/heads/{$branch}^{commit}"]);
    $branchTip = trim($branchTipResult['stdout']);
    $candidateCommit = $receipt['candidate_commit'] ?? null;
    $acceptedFeatureTip = $receipt['accepted_feature_tip'] ?? null;
    $acceptedMainTip = $receipt['accepted_main_tip'] ?? null;

    if (
        $branchTipResult['exit_code'] !== 0
        || ! is_string($candidateCommit)
        || ! is_string($acceptedFeatureTip)
        || ! is_string($acceptedMainTip)
        || preg_match('/^[0-9a-f]{40}$/', $acceptedMainTip) !== 1
        || ! hash_equals($branchTip, $candidateCommit)
        || ! hash_equals($branchTip, $acceptedFeatureTip)
    ) {
        return false;
    }

    $acceptedMainAncestry = run_git($root, [
        'merge-base',
        '--is-ancestor',
        $acceptedMainTip,
        $branchTip,
    ]);

    if ($acceptedMainAncestry['exit_code'] !== 0) {
        return false;
    }

    $loopPath = $archiveDir.'/loop.md';
    $loop = (string) file_get_contents($loopPath);

    if (
        ! orbitLoopIsCompact($loop)
        || branch_name_from_loop($loop) !== $branch
        || orbitLoopLabel($loop, 'Proof', 'Accepted feature tip') !== $acceptedFeatureTip
        || orbitLoopLabel($loop, 'Proof', 'Accepted main tip') !== $acceptedMainTip
    ) {
        return false;
    }

    $copiedEntries = $receipt['copied_entries'] ?? null;
    $entryDigests = $receipt['entry_digests'] ?? null;

    if (! is_array($copiedEntries) || ! is_array($entryDigests)) {
        return false;
    }

    foreach ($copiedEntries as $entry) {
        if (! is_string($entry)) {
            return false;
        }
    }

    $sortedEntries = $copiedEntries;
    sort($sortedEntries);

    if (
        $copiedEntries !== $sortedEntries
        || count($copiedEntries) !== count(array_unique($copiedEntries))
        || ! in_array('loop.md', $copiedEntries, true)
        || array_keys($entryDigests) !== $copiedEntries
    ) {
        return false;
    }

    try {
        $loopProofEntries = compact_archive_loop_proof_entries_for_schema($loop, $schemaVersion);
    } catch (RuntimeException) {
        return false;
    }

    $receiptProofEntries = array_values(array_filter(
        $copiedEntries,
        static fn (string $entry): bool => compact_archive_entry_is_proof_root($entry, $schemaVersion),
    ));

    if ($receiptProofEntries !== $loopProofEntries) {
        return false;
    }

    $actualEntries = compact_archive_actual_entries($archiveDir, $schemaVersion);

    if ($actualEntries === null || $actualEntries !== $copiedEntries) {
        return false;
    }

    foreach ($copiedEntries as $entry) {
        if (! is_string($entry) || ! compact_archive_entry_path_is_allowed($entry, $schemaVersion)) {
            return false;
        }

        $expectedDigest = $entryDigests[$entry] ?? null;

        try {
            $actualDigest = compact_archive_entry_digest($archiveDir.'/'.$entry);
        } catch (Throwable) {
            return false;
        }

        if (
            ! is_string($expectedDigest)
            || preg_match('/^[0-9a-f]{64}$/', $expectedDigest) !== 1
            || $actualDigest === null
            || ! hash_equals($expectedDigest, $actualDigest)
        ) {
            return false;
        }
    }

    return true;
}

/**
 * @return list<string>
 */
function compact_archive_loop_proof_entries_for_schema(string $loop, int $schemaVersion): array
{
    $entries = array_map(
        static fn (string $reference): string => substr($reference, strlen('.orbit/')),
        orbitLoopProofReferences($loop),
    );

    $entries = array_values(array_filter(
        $entries,
        static fn (string $entry): bool => compact_archive_entry_is_proof_root($entry, $schemaVersion),
    ));
    sort($entries, SORT_STRING);

    return $entries;
}

function compact_archive_entry_is_proof_root(string $entry, int $schemaVersion): bool
{
    foreach (compact_archive_proof_root_prefixes($schemaVersion) as $prefix) {
        if (str_starts_with($entry, $prefix.'/')) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function compact_archive_proof_root_prefixes(int $schemaVersion): array
{
    return match ($schemaVersion) {
        2 => ['evidence', 'quality-gates'],
        3 => ['evidence', 'quality-gates', 'release-evidence'],
        default => [],
    };
}

function compact_archive_entry_digest(string $path): ?string
{
    if (is_link($path) || ! is_file($path)) {
        return null;
    }

    return hash_file('sha256', $path) ?: null;
}

function compact_archive_entry_path_is_allowed(string $entry, int $schemaVersion): bool
{
    if (in_array($entry, ['feedback.jsonl', 'loop.md'], true)) {
        return true;
    }

    $roots = implode('|', array_map(
        static fn (string $root): string => preg_quote($root, '~'),
        compact_archive_proof_root_prefixes($schemaVersion),
    ));

    if ($roots === '') {
        return false;
    }

    return preg_match(
        '~^(?:'.$roots.')/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]*[A-Za-z0-9_-]$~',
        $entry,
    ) === 1
        && ! in_array('.', explode('/', $entry), true)
        && ! in_array('..', explode('/', $entry), true);
}

/** @return list<string>|null */
function compact_archive_actual_entries(string $archiveDir, int $schemaVersion): ?array
{
    $entries = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($archiveDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    $roots = implode('|', array_map(
        static fn (string $root): string => preg_quote($root, '~'),
        compact_archive_proof_root_prefixes($schemaVersion),
    ));

    if ($roots === '') {
        return null;
    }

    foreach ($iterator as $entry) {
        $relative = ltrim(substr($entry->getPathname(), strlen(rtrim($archiveDir, '/'))), '/');

        if ($relative === 'orbit-session-archive.json') {
            continue;
        }

        if ($entry->isLink()) {
            return null;
        }

        if ($entry->isDir()) {
            if (
                preg_match('~^(?:'.$roots.')(?:/[A-Za-z0-9._-]+)*$~', $relative) !== 1
                || in_array('.', explode('/', $relative), true)
                || in_array('..', explode('/', $relative), true)
            ) {
                return null;
            }

            continue;
        }

        if (! $entry->isFile() || ! compact_archive_entry_path_is_allowed($relative, $schemaVersion)) {
            return null;
        }

        $entries[] = $relative;
    }

    sort($entries, SORT_STRING);

    return $entries;
}
