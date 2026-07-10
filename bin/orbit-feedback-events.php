<?php

declare(strict_types=1);

require_once __DIR__.'/orbit-secret-rules.php';

const ORBIT_FEEDBACK_TYPES = [
    'feedback.recorded',
    'feedback.promoted',
    'feedback.waived',
    'protection.failed',
];

/**
 * @param array<string, mixed> $event
 */
function orbitFeedbackAppend(string $path, array $event, ?callable $afterInitialInspection = null): void
{
    $directory = dirname($path);
    $directoryIdentity = orbitFeedbackDirectoryIdentity($directory, create: true);
    $initialIdentity = orbitFeedbackPathIdentity($path);

    if (($event['type'] ?? null) === 'feedback.recorded') {
        $sessionRef = $event['session_ref'] ?? null;

        if (! is_string($sessionRef) || ! orbitFeedbackSourceRefIsValid($sessionRef)) {
            throw new RuntimeException('feedback session_ref must be a safe Codex or Solo source reference');
        }
    }

    orbitFeedbackAssertNoSecrets($event);

    if ($afterInitialInspection !== null) {
        $afterInitialInspection();
    }

    $handle = fopen($path, $initialIdentity === null ? 'x+b' : 'c+b');

    if ($handle === false) {
        throw new RuntimeException("Feedback stream changed before append: {$path}");
    }

    try {
        if (! flock($handle, LOCK_EX)) {
            throw new RuntimeException("Unable to lock feedback stream: {$path}");
        }

        orbitFeedbackAssertDirectoryIdentity($directory, $directoryIdentity);
        orbitFeedbackAssertOpenFileIdentity($path, $handle, $initialIdentity);

        rewind($handle);
        $existing = orbitFeedbackDecode((string) stream_get_contents($handle), $path);
        orbitFeedbackValidateStream($existing);
        orbitFeedbackValidate($event, $existing);

        foreach ($existing as $current) {
            if (hash_equals((string) $current['id'], (string) $event['id'])) {
                throw new RuntimeException('duplicate feedback event id: '.$event['id']);
            }
        }

        fseek($handle, 0, SEEK_END);
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if (fwrite($handle, $line) !== strlen($line) || ! fflush($handle)) {
            throw new RuntimeException("Unable to append feedback stream: {$path}");
        }

        if (function_exists('fsync') && ! fsync($handle)) {
            throw new RuntimeException("Unable to sync feedback stream: {$path}");
        }

        orbitFeedbackAssertDirectoryIdentity($directory, $directoryIdentity);
        orbitFeedbackAssertOpenFileIdentity($path, $handle, $initialIdentity);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orbitFeedbackRead(string $path): array
{
    $initialIdentity = orbitFeedbackPathIdentity($path);

    if ($initialIdentity === null) {
        return [];
    }

    $directory = dirname($path);
    $directoryIdentity = orbitFeedbackDirectoryIdentity($directory);
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException("Unable to open feedback stream: {$path}");
    }

    try {
        if (! flock($handle, LOCK_SH)) {
            throw new RuntimeException("Unable to lock feedback stream: {$path}");
        }

        orbitFeedbackAssertDirectoryIdentity($directory, $directoryIdentity);
        orbitFeedbackAssertOpenFileIdentity($path, $handle, $initialIdentity);
        $events = orbitFeedbackDecode((string) stream_get_contents($handle), $path);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    orbitFeedbackValidateStream($events);

    return $events;
}

/** @param list<array<string, mixed>> $events */
function orbitFeedbackValidateStream(array $events): void
{
    $validated = [];
    $seen = [];

    foreach ($events as $event) {
        orbitFeedbackValidate($event, $validated);
        orbitFeedbackAssertNoSecrets($event);

        if (isset($seen[$event['id']])) {
            throw new RuntimeException('duplicate feedback event id: '.$event['id']);
        }

        $seen[$event['id']] = true;
        $validated[] = $event;
    }
}

/**
 * @param array<string, mixed> $event
 * @param list<array<string, mixed>> $existing
 */
function orbitFeedbackValidate(array $event, array $existing = []): void
{
    if (($event['schema_version'] ?? null) !== 1) {
        throw new RuntimeException('feedback event schema_version must be 1');
    }

    $type = $event['type'] ?? null;

    if (! is_string($type) || ! in_array($type, ORBIT_FEEDBACK_TYPES, true)) {
        throw new RuntimeException('unknown feedback event type');
    }

    foreach (['id', 'recorded_at'] as $field) {
        if (! is_string($event[$field] ?? null) || trim($event[$field]) === '') {
            throw new RuntimeException("feedback event requires {$field}");
        }
    }

    if ($type === 'feedback.recorded') {
        orbitFeedbackRequireStrings($event, ['raw_text', 'session_ref', 'surface']);

        if (! orbitFeedbackSourceRefIsValid((string) $event['session_ref'])) {
            throw new RuntimeException('feedback session_ref must be a safe Codex or Solo source reference');
        }

        if (preg_match('/^[a-z0-9]+(?:[.-][a-z0-9]+)*$/', (string) $event['surface']) !== 1) {
            throw new RuntimeException('feedback surface must be a dotted safe identifier');
        }

        if (preg_match('/^[0-9a-f]{40}$/', (string) ($event['candidate_commit'] ?? '')) !== 1) {
            throw new RuntimeException('feedback candidate_commit must be an exact 40-character commit id');
        }

        if (! is_array($event['context'] ?? null) || ! is_array($event['evidence'] ?? null)) {
            throw new RuntimeException('feedback recorded context and evidence must be arrays');
        }

        return;
    }

    orbitFeedbackRequireStrings($event, ['feedback_id']);
    $recordedIds = array_column(array_filter(
        $existing,
        static fn (array $candidate): bool => ($candidate['type'] ?? null) === 'feedback.recorded',
    ), 'id');

    if (! in_array($event['feedback_id'], $recordedIds, true)) {
        throw new RuntimeException('feedback event references unknown feedback event: '.$event['feedback_id']);
    }

    if ($type === 'feedback.promoted') {
        orbitFeedbackRequireStrings($event, ['scope', 'expectation']);
        $protection = $event['protection'] ?? null;

        if (! is_array($protection)) {
            throw new RuntimeException('feedback promotion requires protection');
        }

        orbitFeedbackRequireStrings($protection, ['kind', 'reference', 'rejected_example', 'accepted_example']);

        return;
    }

    if ($type === 'feedback.waived') {
        orbitFeedbackRequireStrings($event, ['source', 'source_ref', 'reason', 'user_message']);

        if ($event['source'] !== 'user') {
            throw new RuntimeException('feedback waiver requires source=user');
        }

        if (! orbitFeedbackSourceRefIsValid((string) $event['source_ref'])) {
            throw new RuntimeException('feedback waiver requires a safe Codex or Solo source reference');
        }

        return;
    }

    orbitFeedbackRequireStrings($event, ['protection', 'reason']);
}

function orbitFeedbackSourceRefIsValid(string $reference): bool
{
    if ($reference === '' || strlen($reference) > 512 || orbitSecretFindings($reference) !== []) {
        return false;
    }

    $anchor = '(?:#[A-Za-z0-9][A-Za-z0-9._:-]{0,127})?';

    return (
        preg_match('~^codex://threads/[A-Za-z0-9][A-Za-z0-9-]{0,127}'.$anchor.'$~', $reference) === 1
        || preg_match('~^solo://[A-Za-z0-9][A-Za-z0-9._/\~-]{0,255}'.$anchor.'$~', $reference) === 1
    );
}

/** @param array<string, mixed> $event */
function orbitFeedbackAssertNoSecrets(array $event): void
{
    $walk = static function (mixed $value, string $path) use (&$walk): void {
        if (is_string($value)) {
            $finding = orbitSecretFindings($value)[0] ?? null;

            if ($finding !== null) {
                throw new RuntimeException(
                    "feedback event contains secret-shaped metadata at {$path} rule={$finding['rule']}",
                );
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $item) {
            $walk($item, $path === '' ? (string) $key : $path.'.'.$key);
        }
    };

    $walk($event, '');
}

/** @return array{dev: int, ino: int, mode: int} */
function orbitFeedbackDirectoryIdentity(string $directory, bool $create = false): array
{
    $stat = @lstat($directory);

    if ($stat === false && $create) {
        $parent = dirname($directory);
        $parentStat = @lstat($parent);

        if (
            $parentStat === false
            || ! orbitFeedbackStatIsDirectory($parentStat)
            || ! @mkdir($directory, 0o775, false)
        ) {
            throw new RuntimeException("Unable to create feedback directory: {$directory}");
        }

        $stat = @lstat($directory);
    }

    if ($stat === false || ! orbitFeedbackStatIsDirectory($stat)) {
        throw new RuntimeException("feedback directory must be a real directory, not a symlink: {$directory}");
    }

    return ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'], 'mode' => (int) $stat['mode']];
}

/** @param array{dev: int, ino: int, mode: int} $expected */
function orbitFeedbackAssertDirectoryIdentity(string $directory, array $expected): void
{
    $actual = orbitFeedbackDirectoryIdentity($directory);

    if ($actual !== $expected) {
        throw new RuntimeException("Feedback directory changed before append: {$directory}");
    }
}

/** @return array{dev: int, ino: int, mode: int}|null */
function orbitFeedbackPathIdentity(string $path): ?array
{
    $stat = @lstat($path);

    if ($stat === false) {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException("Unable to inspect feedback stream: {$path}");
        }

        return null;
    }

    if (! orbitFeedbackStatIsRegular($stat)) {
        throw new RuntimeException("Feedback stream must be a regular file, not a symlink: {$path}");
    }

    return ['dev' => (int) $stat['dev'], 'ino' => (int) $stat['ino'], 'mode' => (int) $stat['mode']];
}

/**
 * @param resource $handle
 * @param array{dev: int, ino: int, mode: int}|null $initial
 */
function orbitFeedbackAssertOpenFileIdentity(
    string $path,
    $handle,
    ?array $initial,
): void {
    $opened = fstat($handle);
    try {
        $current = orbitFeedbackPathIdentity($path);
    } catch (RuntimeException $exception) {
        throw new RuntimeException("Feedback stream changed before append: {$path}", previous: $exception);
    }

    if ($opened === false || $current === null || ! orbitFeedbackStatIsRegular($opened)) {
        throw new RuntimeException("Feedback stream changed before append: {$path}");
    }

    $openedIdentity = [
        'dev' => (int) $opened['dev'],
        'ino' => (int) $opened['ino'],
        'mode' => (int) $opened['mode'],
    ];

    if ($openedIdentity !== $current || $initial !== null && $openedIdentity !== $initial) {
        throw new RuntimeException("Feedback stream changed before append: {$path}");
    }
}

/** @param array<int|string, mixed> $stat */
function orbitFeedbackStatIsRegular(array $stat): bool
{
    return ((int) $stat['mode'] & 0o170000) === 0o100000;
}

/** @param array<int|string, mixed> $stat */
function orbitFeedbackStatIsDirectory(array $stat): bool
{
    return ((int) $stat['mode'] & 0o170000) === 0o040000;
}

/**
 * @param array<string, mixed> $payload
 * @param list<string> $fields
 */
function orbitFeedbackRequireStrings(array $payload, array $fields): void
{
    foreach ($fields as $field) {
        if (! is_string($payload[$field] ?? null) || trim($payload[$field]) === '') {
            throw new RuntimeException("feedback event requires {$field}");
        }
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orbitFeedbackDecode(string $contents, string $path): array
{
    $events = [];

    foreach (preg_split('/\R/', $contents) ?: [] as $lineNumber => $line) {
        if (trim($line) === '') {
            continue;
        }

        try {
            $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid feedback JSONL at {$path}:".($lineNumber + 1), previous: $exception);
        }

        if (! is_array($event)) {
            throw new RuntimeException("Invalid feedback event at {$path}:".($lineNumber + 1));
        }

        $events[] = $event;
    }

    return $events;
}
