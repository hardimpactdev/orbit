<?php

declare(strict_types=1);

const ORBIT_LOOP_STATES = ['frame', 'build', 'prove', 'accept', 'accepted', 'land', 'blocked'];

const ORBIT_LOOP_SLICE_STATES = ['pending', 'ready', 'building', 'complete', 'blocked'];

const ORBIT_LOOP_ACCEPTANCE_VENUES = ['automated', 'retained-incus', 'browser', 'host-macos'];

const ORBIT_LOOP_BLAST_RADIUS_AUTHORITY_PATHS = [
    'PRODUCT_DECISIONS.md',
    'apps/docs/content/architecture.md',
    'apps/docs/content/concepts.md',
    'apps/docs/content/domains/authorization-matrix.md',
    'apps/docs/content/execution-lanes.md',
    'apps/docs/content/mission.md',
    'apps/docs/content/tech-stack.md',
];

const ORBIT_LOOP_BLAST_RADIUS_AUTHORITY_PREFIXES = [
    'apps/docs/content/domains/',
];

/**
 * Parse the exact indexed Slices table without reading packet files.
 *
 * @return list<array{path:string,state:string,checkpoint:string}>
 */
function orbitLoopSlices(string $markdown): array
{
    $body = orbitLoopExactSlicesSection($markdown);

    $lines = array_values(array_filter(
        preg_split('/\R/', $body) ?: [],
        static fn (string $line): bool => trim($line) !== '',
    ));
    if (($lines[0] ?? null) !== '| Slice | State | Checkpoint |') {
        throw new RuntimeException('Slices table must have exact headers');
    }
    if (($lines[1] ?? null) !== '| --- | --- | --- |') {
        throw new RuntimeException('Slices table must have exact separators');
    }

    $rows = [];
    $paths = [];
    foreach (array_slice($lines, 2) as $line) {
        if (! str_starts_with($line, '|')) {
            throw new RuntimeException('duplicate slice path or malformed row');
        }
        if (substr_count($line, '|') !== 4) {
            throw new RuntimeException('Slices table row must have exactly 3 columns');
        }
        if (preg_match('/^\| `([^`]+)` \| ([^|]+) \| ([^|]+) \|$/', $line, $match) !== 1) {
            throw new RuntimeException('duplicate slice path or malformed row');
        }
        $path = $match[1];
        if (preg_match('~^\.orbit/slices/[0-9]{2}-[a-z0-9][a-z0-9-]*\.md$~', $path) !== 1) {
            throw new RuntimeException("unsafe slice packet path: {$path}");
        }
        if (isset($paths[$path])) {
            throw new RuntimeException("duplicate slice path: {$path}");
        }
        $paths[$path] = true;
        $state = $match[2];
        if (! in_array($state, ORBIT_LOOP_SLICE_STATES, true)) {
            throw new RuntimeException("invalid slice state for {$path}: {$state}");
        }
        $checkpoint = $match[3];
        if ($checkpoint !== 'none' && preg_match('/^[0-9a-f]{40}$/', $checkpoint) !== 1) {
            throw new RuntimeException("invalid slice checkpoint for {$path}");
        }
        $rows[] = ['path' => $path, 'state' => $state, 'checkpoint' => $checkpoint];
    }
    if ($rows === []) {
        throw new RuntimeException('Slices table is empty');
    }

    return $rows;
}

function orbitLoopExactSlicesSection(string $markdown): string
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $headingIndexes = [];
    foreach ($lines as $index => $line) {
        if ($line === '## Slices') {
            $headingIndexes[] = $index;

            continue;
        }
        if (preg_match('/^##\s*(.+?)\s*$/', $line, $match) === 1 && strtolower($match[1]) === 'slices') {
            throw new RuntimeException('Slices section heading must be exactly `## Slices`');
        }
    }
    if ($headingIndexes === []) {
        throw new RuntimeException('Slices section is missing');
    }
    if (count($headingIndexes) !== 1) {
        throw new RuntimeException('duplicate Slices section');
    }

    $section = [];
    foreach (array_slice($lines, $headingIndexes[0] + 1) as $line) {
        if (preg_match('/^##\s+/', $line) === 1) {
            break;
        }
        $section[] = $line;
    }

    return trim(implode("\n", $section));
}

function orbitLoopReadSlicePacket(string $path, string $relativePath): string
{
    $initial = @lstat($path);
    if ($initial === false || is_link($path) || ($initial['mode'] & 0170000) !== 0100000) {
        throw new RuntimeException("unsafe slice packet: {$relativePath}");
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("unsafe slice packet: {$relativePath}");
    }
    try {
        $opened = fstat($handle);
        if (
            $opened === false
            || $opened['dev'] !== $initial['dev']
            || $opened['ino'] !== $initial['ino']
            || ($opened['mode'] & 0170000) !== 0100000
        ) {
            throw new RuntimeException("unsafe slice packet: {$relativePath}");
        }
        $contents = stream_get_contents($handle);
        $current = @lstat($path);
        if (
            $contents === false
            || $current === false
            || $current['dev'] !== $initial['dev']
            || $current['ino'] !== $initial['ino']
            || ($current['mode'] & 0170000) !== 0100000
        ) {
            throw new RuntimeException("unsafe slice packet: {$relativePath}");
        }
        return $contents;
    } finally {
        fclose($handle);
    }
}

/**
 * @return array<string, array{path:string,state:string,checkpoint:string,depends:list<string>}>
 */
function orbitLoopSlicePacketRowsValidated(string $markdown, string $orbitDir): array
{
    $rows = orbitLoopSlices($markdown);
    $orbitStat = @lstat($orbitDir);
    $sliceDir = $orbitDir.'/slices';
    $sliceStat = @lstat($sliceDir);
    if (
        $orbitStat === false
        || is_link($orbitDir)
        || ($orbitStat['mode'] & 0170000) !== 0040000
        || $sliceStat === false
        || is_link($sliceDir)
        || ($sliceStat['mode'] & 0170000) !== 0040000
    ) {
        throw new RuntimeException('unsafe slice packet directory');
    }

    $indexed = [];
    foreach ($rows as $row) {
        $id = basename($row['path'], '.md');
        $packet = orbitLoopReadSlicePacket($sliceDir.'/'.$id.'.md', $row['path']);
        orbitLoopValidateSlicePacket($packet, $id);
        $indexed[$id] = [...$row, 'depends' => orbitLoopSliceDependencies($packet, $id)];
    }

    foreach (scandir($sliceDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || ! str_ends_with($entry, '.md')) {
            continue;
        }
        $entryPath = $sliceDir.'/'.$entry;
        if (! is_file($entryPath) || is_link($entryPath)) {
            throw new RuntimeException('slice packet directory contains unsafe entry');
        }
        $id = basename($entry, '.md');
        if (! isset($indexed[$id])) {
            throw new RuntimeException("unindexed slice packet: .orbit/slices/{$entry}");
        }
    }

    foreach ($indexed as $id => $row) {
        foreach ($row['depends'] as $dependency) {
            if (! isset($indexed[$dependency])) {
                throw new RuntimeException("slice {$id} depends on unknown slice: {$dependency}");
            }
            if ($dependency === $id) {
                throw new RuntimeException("slice {$id} cannot depend on itself");
            }
        }
    }

    $visiting = [];
    $visited = [];
    $visit = static function (string $id) use (&$visit, &$visiting, &$visited, $indexed): void {
        if (isset($visiting[$id])) {
            throw new RuntimeException("cyclic slice dependency: {$id}");
        }
        if (isset($visited[$id])) {
            return;
        }
        $visiting[$id] = true;
        foreach ($indexed[$id]['depends'] as $dependency) {
            $visit($dependency);
        }
        unset($visiting[$id]);
        $visited[$id] = true;
    };
    foreach (array_keys($indexed) as $id) {
        $visit($id);
    }

    return $indexed;
}

function orbitLoopValidateSlicePacket(string $packet, string $id): void
{
    $packet = str_replace(["\r\n", "\r"], "\n", $packet);
    if (
        preg_match_all('/^# [^\r\n]*$/m', $packet, $matches) !== 1
        || preg_match_all('/^# Orbit Feature Slice$/m', $packet, $matches) !== 1
    ) {
        throw new RuntimeException("slice packet {$id} requires exactly one # Orbit Feature Slice");
    }
    if (preg_match_all('/^- Slice: ([^\r\n]*)$/m', $packet, $matches) !== 1) {
        throw new RuntimeException("slice packet {$id} requires exactly one Slice label");
    }
    if ($matches[1][0] !== $id) {
        throw new RuntimeException("slice packet {$id} Slice label does not match filename");
    }
    if (preg_match_all('/^- Depends on: ([^\r\n]*)$/m', $packet, $matches) !== 1) {
        throw new RuntimeException("slice packet {$id} requires exactly one Depends on label");
    }
    if (preg_match('/<[^>\r\n]+>/', $packet) === 1) {
        throw new RuntimeException("slice packet {$id} contains a placeholder");
    }
    $lines = explode("\n", $packet);
    $lineIndex = 1;
    while (($lines[$lineIndex] ?? null) === '') {
        $lineIndex++;
    }
    if (
        preg_match('/^- Slice: ([^\r\n]*)$/', $lines[$lineIndex] ?? '', $sliceMatch) !== 1
        || $sliceMatch[1] !== $id
    ) {
        throw new RuntimeException("slice packet {$id} requires the exact metadata preamble");
    }
    $lineIndex++;
    if (preg_match('/^- Depends on: ([^\r\n]*)$/', $lines[$lineIndex] ?? '') !== 1) {
        throw new RuntimeException("slice packet {$id} requires the exact metadata preamble");
    }
    $lineIndex++;
    while (($lines[$lineIndex] ?? null) === '') {
        $lineIndex++;
    }
    preg_match_all('/^## ([^\r\n]+)$/m', $packet, $matches);
    if ($matches[1] !== ['Outcome', 'Scope', 'Authority', 'Proof']) {
        throw new RuntimeException("slice packet {$id} requires the required packet sections in order");
    }
    if (($lines[$lineIndex] ?? null) !== '## Outcome') {
        throw new RuntimeException("slice packet {$id} requires the exact metadata preamble");
    }
    $labelsBySection = [
        'Scope' => ['Included', 'Excluded'],
        'Authority' => ['Decisions', 'Product docs'],
        'Proof' => ['Focused'],
    ];
    $currentSection = null;
    foreach (preg_split('/\R/', $packet) ?: [] as $line) {
        if (preg_match('/^## ([^\r\n]+)$/', $line, $heading) === 1) {
            $currentSection = $heading[1];

            continue;
        }
        if (preg_match('/^- ([^:]+):(?: .*)?$/', $line, $label) !== 1) {
            continue;
        }
        if ($currentSection === null && ! in_array($label[1], ['Slice', 'Depends on'], true)) {
            throw new RuntimeException("slice packet {$id} has unknown packet label: {$label[1]}");
        }
        if ($currentSection !== null && ! in_array($label[1], $labelsBySection[$currentSection] ?? [], true)) {
            throw new RuntimeException("slice packet {$id} has unknown packet label: {$label[1]}");
        }
    }
    foreach ($labelsBySection as $section => $labels) {
        $body = orbitLoopSection($packet, $section) ?? '';
        $found = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            if (preg_match('/^- ([^:]+):(?: .*)?$/', $line, $label) === 1) {
                $found[] = $label[1];
            }
        }
        if ($found !== $labels) {
            throw new RuntimeException("slice packet {$id} requires {$section} labels: ".implode(', ', $labels));
        }
    }
}

/** @return list<string> */
function orbitLoopSliceDependencies(string $packet, string $id): array
{
    preg_match('/^- Depends on: ([^\r\n]*)$/m', $packet, $match);
    $value = $match[1] ?? '';
    if ($value === 'none') {
        return [];
    }
    $dependencies = [];
    foreach (explode(',', $value) as $dependency) {
        $dependency = trim($dependency);
        if (preg_match('/^[0-9]{2}-[a-z0-9][a-z0-9-]*$/', $dependency) !== 1) {
            throw new RuntimeException("slice {$id} has unsafe dependency: {$dependency}");
        }
        if (in_array($dependency, $dependencies, true)) {
            throw new RuntimeException("slice {$id} has duplicate dependency: {$dependency}");
        }
        $dependencies[] = $dependency;
    }

    return $dependencies;
}

/** @return list<string> */
function orbitLoopSliceFrameProblems(string $markdown, string $orbitDir): array
{
    try {
        $slices = orbitLoopSlicePacketRowsValidated($markdown, $orbitDir);
    } catch (Throwable $exception) {
        return [$exception->getMessage()];
    }
    foreach ($slices as $slice) {
        if ($slice['state'] === 'building') {
            return ['initial slice graph cannot contain a building slice'];
        }
    }
    foreach ($slices as $id => $slice) {
        if (in_array($slice['state'], ['ready', 'building', 'complete'], true)) {
            foreach ($slice['depends'] as $dependency) {
                if ($slices[$dependency]['state'] !== 'complete') {
                    return ["{$slice['state']} slice {$id} has incomplete dependency"];
                }
            }
        }
    }
    $hasReadyWork = false;
    foreach ($slices as $slice) {
        if ($slice['state'] === 'ready' && $slice['depends'] === []) {
            $hasReadyWork = true;

            break;
        }
    }
    if ($hasReadyWork) {
        foreach ($slices as $id => $slice) {
            if (
                $slice['state'] === 'pending'
                && array_reduce(
                    $slice['depends'],
                    static function (bool $complete, string $dependency) use ($slices): bool {
                        return $complete && $slices[$dependency]['state'] === 'complete';
                    },
                    true,
                )
            ) {
                return ["pending slice {$id} has complete dependencies"];
            }
        }

        return [];
    }

    return ['initial graph requires ready dependency-free work'];
}

/** @return list<string> */
function orbitLoopSliceFinalizationProblems(string $markdown, string $worktree, string $featureTip): array
{
    try {
        $slices = orbitLoopSlicePacketRowsValidated($markdown, $worktree.'/.orbit');
    } catch (Throwable $exception) {
        return [$exception->getMessage()];
    }
    $phase = orbitLoopStatusHead(orbitLoopLabel($markdown, 'Status', 'State'));
    $terminal = in_array($phase, ['prove', 'accept', 'accepted', 'land'], true);
    if ($terminal) {
        foreach ($slices as $id => $slice) {
            if ($slice['state'] !== 'complete') {
                return ["terminal phase requires every indexed slice to be complete: {$id}"];
            }
        }
        $previous = null;
        foreach ($slices as $id => $slice) {
            $checkpoint = $slice['checkpoint'];
            if ($checkpoint === 'none') {
                return ["complete slice {$id} requires an exact checkpoint"];
            }
            if (orbitLoopGitExitCode($worktree, ['merge-base', '--is-ancestor', $checkpoint, $featureTip]) !== 0) {
                return ["checkpoint for slice {$id} is not an ancestor of feature HEAD"];
            }
            if ($previous !== null && orbitLoopGitExitCode($worktree, ['merge-base', '--is-ancestor', $previous, $checkpoint]) !== 0) {
                return ["checkpoint chain moves backwards at slice {$id}"];
            }
            $previous = $checkpoint;
        }

        return [];
    }
    $building = array_filter($slices, static function (array $slice): bool {
        return $slice['state'] === 'building';
    });
    if (count($building) > 1) {
        return ['at most one building slice'];
    }
    foreach ($slices as $id => $slice) {
        if (in_array($slice['state'], ['ready', 'building', 'complete'], true)) {
            foreach ($slice['depends'] as $dependency) {
                if ($slices[$dependency]['state'] !== 'complete') {
                    return ["{$slice['state']} slice {$id} has incomplete dependency"];
                }
            }
        }
        if (
            $slice['state'] === 'pending'
            && array_reduce(
                $slice['depends'],
                static function (bool $complete, string $dependency) use ($slices): bool {
                    return $complete && $slices[$dependency]['state'] === 'complete';
                },
                true,
            )
        ) {
            return ["pending slice {$id} has complete dependencies"];
        }
    }
    $allComplete = array_reduce(
        $slices,
        static fn (bool $complete, array $slice): bool => $complete && $slice['state'] === 'complete',
        true,
    );
    if ($allComplete) {
        return [];
    }
    $hasWork = array_filter($slices, static function (array $slice): bool {
        return in_array($slice['state'], ['ready', 'building'], true);
    });
    if ($hasWork === []) {
        return ['incomplete slice graph has no ready or building work'];
    }

    return [];
}

/** @param list<string> $arguments */
function orbitLoopGitExitCode(string $cwd, array $arguments): int
{
    $process = proc_open(['git', '-C', $cwd, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        return 1;
    }
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return proc_close($process);
}

function orbitLoopIsCompact(string $markdown): bool
{
    return (
        str_contains($markdown, '# Orbit Feature Loop')
        && orbitLoopSection($markdown, 'Proof') !== null
        && orbitLoopSection($markdown, 'Status') !== null
    );
}

function orbitLoopSection(string $markdown, string $heading): ?string
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $capturing = false;
    $section = [];

    foreach ($lines as $line) {
        if (preg_match('/^##\s+'.preg_quote($heading, '/').'\s*$/i', $line) === 1) {
            $capturing = true;

            continue;
        }

        if ($capturing && preg_match('/^##\s+/', $line) === 1) {
            break;
        }

        if ($capturing) {
            $section[] = $line;
        }
    }

    return $capturing ? trim(implode("\n", $section)) : null;
}

function orbitLoopLabel(string $markdown, string $section, string $label): ?string
{
    $body = orbitLoopSection($markdown, $section);

    if ($body === null) {
        return null;
    }

    if (preg_match('/^-\s+'.preg_quote($label, '/').':\s*(.*)$/mi', $body, $match) !== 1) {
        return null;
    }

    return trim($match[1]);
}

function orbitLoopNestedLabel(string $markdown, string $section, string $parent, string $label): ?string
{
    $body = orbitLoopSection($markdown, $section);

    if ($body === null) {
        return null;
    }

    $pattern =
        '/^-\s+'
        .preg_quote($parent, '/')
        .':\s*\R'
        .'(?:(?:\s{2,}.*)\R)*?\s{2,}-\s+'
        .preg_quote($label, '/')
        .':\s*(.*)$/mi';

    if (preg_match($pattern, $body, $match) !== 1) {
        return null;
    }

    return trim($match[1]);
}

function orbitLoopSetLabel(string $markdown, string $section, string $label, string $value): string
{
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new InvalidArgumentException('Loop label values must fit on one line.');
    }

    $lines = preg_split('/\R/', $markdown) ?: [];
    $inSection = false;
    $matches = 0;

    foreach ($lines as $index => $line) {
        if (preg_match('/^##\s+'.preg_quote($section, '/').'\s*$/i', $line) === 1) {
            $inSection = true;

            continue;
        }

        if ($inSection && preg_match('/^##\s+/', $line) === 1) {
            $inSection = false;
        }

        if (! $inSection || preg_match('/^-\s+'.preg_quote($label, '/').':\s*.*$/i', $line) !== 1) {
            continue;
        }

        $lines[$index] = '- '.$label.': '.$value;
        $matches++;
    }

    if ($matches !== 1) {
        throw new RuntimeException("Expected exactly one `{$label}` label in `{$section}`; found {$matches}.");
    }

    return implode("\n", $lines);
}

function orbitLoopSetNestedLabel(
    string $markdown,
    string $section,
    string $parent,
    string $label,
    string $value,
): string {
    if (str_contains($value, "\n") || str_contains($value, "\r")) {
        throw new InvalidArgumentException('Loop label values must fit on one line.');
    }

    $lines = preg_split('/\R/', $markdown) ?: [];
    $inSection = false;
    $inParent = false;
    $matches = 0;

    foreach ($lines as $index => $line) {
        if (preg_match('/^##\s+'.preg_quote($section, '/').'\s*$/i', $line) === 1) {
            $inSection = true;
            $inParent = false;

            continue;
        }

        if ($inSection && preg_match('/^##\s+/', $line) === 1) {
            $inSection = false;
            $inParent = false;
        }

        if (! $inSection) {
            continue;
        }

        if (preg_match('/^-\s+'.preg_quote($parent, '/').':\s*$/i', $line) === 1) {
            $inParent = true;

            continue;
        }

        if ($inParent && preg_match('/^-\s+/', $line) === 1) {
            $inParent = false;
        }

        if (! $inParent || preg_match('/^(\s+)-\s+'.preg_quote($label, '/').':\s*.*$/i', $line, $match) !== 1) {
            continue;
        }

        $lines[$index] = $match[1].'- '.$label.': '.$value;
        $matches++;
    }

    if ($matches !== 1) {
        throw new RuntimeException(
            "Expected exactly one `{$label}` label below `{$parent}` in `{$section}`; found {$matches}.",
        );
    }

    return implode("\n", $lines);
}

function orbitLoopStatusHead(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $head = strtolower(trim((string) preg_replace('/\s+-\s+.*/', '', $value)));

    return $head === '' ? null : $head;
}

function orbitLoopReviewPassed(?string $review): bool
{
    return orbitLoopStatusHead($review) === 'passed';
}

function orbitLoopReviewHumanJudgment(?string $review): ?string
{
    if (! orbitLoopReviewPassed($review)) {
        return null;
    }

    if (preg_match('/\bhuman-judgment=(required|not-required)\b/i', (string) $review, $match) === 1) {
        return strtolower($match[1]);
    }

    if (preg_match('/\bnon[- ]observable\b/i', (string) $review) === 1) {
        return 'not-required';
    }

    return preg_match('/\bobservable\b/i', (string) $review) === 1 ? 'required' : null;
}

function orbitLoopReviewRequiresHumanJudgment(?string $review): bool
{
    return orbitLoopReviewHumanJudgment($review) === 'required';
}

function orbitLoopReviewSaysNoHumanJudgment(?string $review): bool
{
    return orbitLoopReviewHumanJudgment($review) === 'not-required';
}

/** @param list<string> $changedFiles */
function orbitLoopBlastRadiusProblem(string $markdown, array $changedFiles = []): ?string
{
    $blastRadius = orbitLoopLabel($markdown, 'Proof', 'Blast radius');
    $status = orbitLoopStatusHead($blastRadius);

    if ($status === null) {
        return 'Blast radius is missing';
    }

    if (in_array($status, ['pending', 'gaps'], true)) {
        return "Blast radius is {$status}";
    }

    if (! in_array($status, ['not-required', 'complete'], true)) {
        return "Blast radius must be not-required or complete; current: {$status}";
    }

    if ($status === 'not-required') {
        if (preg_match('/^not-required\s+-\s+\S.+$/i', (string) $blastRadius) !== 1) {
            return 'Blast radius not-required must include a reason';
        }

        if (orbitLoopBlastRadiusRequiresClosure($changedFiles)) {
            return 'Blast radius must be complete for a high-authority product contract change';
        }

        return null;
    }

    if (preg_match('/^complete\s+-\s+evidence=\S.+;\s*result=\S.+$/i', (string) $blastRadius) !== 1) {
        return 'Blast radius complete must record evidence=<repository-wide search, inventory, or lintable check>; result=<summary>';
    }

    return null;
}

/** @param list<string> $changedFiles */
function orbitLoopBlastRadiusRequiresClosure(array $changedFiles): bool
{
    foreach ($changedFiles as $path) {
        $normalizedPath = ltrim((string) $path, './');

        if (in_array($normalizedPath, ORBIT_LOOP_BLAST_RADIUS_AUTHORITY_PATHS, true)) {
            return true;
        }

        foreach (ORBIT_LOOP_BLAST_RADIUS_AUTHORITY_PREFIXES as $prefix) {
            if (str_starts_with($normalizedPath, $prefix)) {
                return true;
            }
        }
    }

    return false;
}

/** @return list<string> */
function orbitLoopProofReferences(string $markdown): array
{
    preg_match_all(
        '~\.orbit/(?:evidence|quality-gates|release-evidence)/~',
        $markdown,
        $markers,
        PREG_OFFSET_CAPTURE,
    );

    $references = [];

    foreach ($markers[0] ?? [] as $marker) {
        if (! is_array($marker) || ! isset($marker[1]) || ! is_int($marker[1])) {
            continue;
        }

        $markerOffset = $marker[1];
        $beforeOpeningDelimiter = $markerOffset > 1
            ? substr($markdown, $markerOffset - 2, 1)
            : '';

        $openingDelimiterIsExact =
            $markerOffset > 0
            && substr($markdown, $markerOffset - 1, 1) === '`'
            && ! in_array($beforeOpeningDelimiter, ['`', '\\'], true);

        if (! $openingDelimiterIsExact) {
            throw new RuntimeException(
                'Compact cited proof must be one exact inline-code path: '
                    .orbitLoopProofReferenceContainingToken($markdown, $markerOffset),
            );
        }

        $candidate = substr($markdown, $markerOffset);
        $matched = preg_match(
            '~^\.orbit/(?:evidence|quality-gates|release-evidence)/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]*[A-Za-z0-9_-]~',
            $candidate,
            $match,
        );

        if ($matched !== 1) {
            throw new RuntimeException(
                'Compact cited proof has an unsafe or malformed path: '.orbitLoopProofReferenceToken($candidate),
            );
        }

        $reference = $match[0];
        $token = orbitLoopProofReferenceToken($candidate);
        $following = substr($candidate, strlen($reference), 1);
        $afterClosingDelimiter = substr($candidate, strlen($reference) + 1, 1);

        if ($following !== '`' || $afterClosingDelimiter === '`') {
            throw new RuntimeException(
                'Compact cited proof must be one exact inline-code path: '.$token,
            );
        }

        $references[] = $reference;
    }

    $references = array_values(array_unique($references));
    sort($references, SORT_STRING);

    return $references;
}

function orbitLoopProofReferenceContainingToken(string $markdown, int $offset): string
{
    $start = $offset;

    while ($start > 0) {
        $preceding = substr($markdown, $start - 1, 1);

        if (preg_match('~^[\s`\'"<>]$~u', $preceding) === 1) {
            break;
        }

        $start--;
    }

    return orbitLoopProofReferenceToken(substr($markdown, $start));
}

function orbitLoopProofReferenceToken(string $candidate): string
{
    if (preg_match('~^[^\s`\'"<>]+~u', $candidate, $match) === 1) {
        return $match[0];
    }

    return substr($candidate, 0, 120);
}

function orbitLoopReviewedIdentityProblem(string $markdown, string $featureTip): ?string
{
    $review = orbitLoopLabel($markdown, 'Proof', 'Review');

    if (! orbitLoopReviewPassed($review)) {
        return 'Review must be passed';
    }

    if (orbitLoopReviewHumanJudgment($review) === null) {
        return 'Review must record human-judgment=required or human-judgment=not-required';
    }

    $reviewedFeature = orbitLoopLabel($markdown, 'Proof', 'Reviewed feature tip');

    if ($reviewedFeature === null || ! hash_equals($featureTip, $reviewedFeature)) {
        return 'reviewed feature tip does not equal candidate HEAD';
    }

    return null;
}

function orbitLoopTopLabel(string $markdown, string $label): ?string
{
    if (preg_match('/^-[ \t]+'.preg_quote($label, '/').':[ \t]*(.+)$/mi', $markdown, $match) !== 1) {
        return null;
    }

    $value = trim($match[1]);

    if (preg_match('/^`([^`\r\n]+)`$/D', $value, $inlineCode) === 1) {
        return $inlineCode[1];
    }

    return $value;
}

function orbitLoopVenueSatisfies(string $actual, string $required): bool
{
    return (
        in_array($actual, ORBIT_LOOP_ACCEPTANCE_VENUES, true)
        && ($actual === $required || $required === 'automated')
    );
}

const ORBIT_LOOP_RUNTIME_RECEIPT_ALLOWED_KEYS = [
    'candidate',
    'venue',
    'environment',
    'expected',
    'observed',
    'result',
    'evidence',
    'target',
    'command',
];

const ORBIT_LOOP_SCOPE_TRANSITION_KEYS = [
    'success',
    'failure',
    'retry',
    'stop-restart',
    'stale',
];

/**
 * Optional compact Scope framing for stateful, lifecycle, or concrete UX work.
 *
 * Parse only the Scope Owned row. Other Scope rows may mention the marker text
 * without enabling framing. When neither marker appears on Owned, ordinary and
 * legacy loops stay valid. When primitive= or transitions= appears on Owned,
 * require the compact syntax only: exact primitive plus the five known
 * transition keys. Values may be n/a. Do not decide whether a feature is
 * stateful or whether prose is correct.
 */
function orbitLoopScopeFramingProblem(string $markdown): ?string
{
    $owned = orbitLoopLabel($markdown, 'Scope', 'Owned');

    if ($owned === null || $owned === '') {
        return null;
    }

    if (preg_match_all('/\b(primitive|transitions)\s*=/i', $owned, $markerMatches, PREG_SET_ORDER) === 0) {
        return null;
    }

    $primitiveCount = 0;
    $transitionsCount = 0;

    foreach ($markerMatches as $markerMatch) {
        if (strtolower($markerMatch[1]) === 'primitive') {
            $primitiveCount++;
        } else {
            $transitionsCount++;
        }
    }

    if ($primitiveCount > 1) {
        return 'Scope framing has duplicate primitive=; use one optional clause on the Owned row';
    }

    if ($transitionsCount > 1) {
        return 'Scope framing has duplicate transitions=; use one optional clause on the Owned row';
    }

    if ($primitiveCount === 0) {
        return 'Scope framing is incomplete: missing primitive=; when framing is used require primitive=<exact> and transitions=success:...|failure:...|retry:...|stop-restart:...|stale:...';
    }

    if ($transitionsCount === 0) {
        return 'Scope framing is incomplete: missing transitions=; when framing is used require primitive=<exact> and transitions=success:...|failure:...|retry:...|stop-restart:...|stale:...';
    }

    if (preg_match('/\bprimitive\s*=\s*([^;\r\n]*)/i', $owned, $primitiveMatch) !== 1) {
        return 'Scope framing primitive= is missing a value; use primitive=<exact requested primitive>';
    }

    $primitive = trim($primitiveMatch[1]);

    if ($primitive === '') {
        return 'Scope framing primitive= is empty; use primitive=<exact requested primitive>';
    }

    if (preg_match('/<[^>\r\n]+>/', $primitive) === 1) {
        return 'Scope framing contains a template placeholder in primitive=; replace placeholders with concrete values';
    }

    if (preg_match('/\btransitions\s*=\s*(.*)$/is', $owned, $transitionsMatch) !== 1) {
        return 'Scope framing transitions= is missing a value; require success|failure|retry|stop-restart|stale keys';
    }

    $transitionsRaw = trim($transitionsMatch[1]);

    if ($transitionsRaw === '') {
        return 'Scope framing transitions= is empty; require success|failure|retry|stop-restart|stale keys';
    }

    if (preg_match('/<[^>\r\n]+>/', $transitionsRaw) === 1) {
        return 'Scope framing contains a template placeholder in transitions=; replace placeholders with concrete values or n/a';
    }

    $parts = explode('|', $transitionsRaw);
    $seen = [];

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '') {
            return 'Scope framing transitions= has an empty segment; use key:value pairs separated by |';
        }

        if (preg_match('/^([a-z0-9-]+)\s*:\s*(.*)$/s', $part, $segmentMatch) !== 1) {
            return "Scope framing transitions= segment is not key:value: {$part}";
        }

        $key = strtolower($segmentMatch[1]);
        $value = trim($segmentMatch[2]);

        if (! in_array($key, ORBIT_LOOP_SCOPE_TRANSITION_KEYS, true)) {
            return "Scope framing transitions= has unknown key: {$key}; allowed keys are success, failure, retry, stop-restart, stale";
        }

        if (array_key_exists($key, $seen)) {
            return "Scope framing transitions= has duplicate key: {$key}";
        }

        if ($value === '') {
            return "Scope framing transitions= value for {$key} is empty; use concrete text or n/a";
        }

        $seen[$key] = $value;
    }

    foreach (ORBIT_LOOP_SCOPE_TRANSITION_KEYS as $requiredKey) {
        if (! array_key_exists($requiredKey, $seen)) {
            return "Scope framing transitions= is missing required key: {$requiredKey}; require success, failure, retry, stop-restart, and stale (use n/a when not applicable)";
        }
    }

    return null;
}

function orbitLoopRuntimeProofProblem(
    string $markdown,
    string $venue,
    string $candidateCommit,
    string $worktree,
): ?string {
    if ($venue === 'automated') {
        return null;
    }

    $runtime = orbitLoopNestedLabel($markdown, 'Proof', 'Verification', 'runtime');
    $requiredVenues = orbitLoopAcceptanceVenues(orbitLoopChangedFilesForRuntime($worktree));
    if (count($requiredVenues) > 1) {
        foreach ($requiredVenues as $requiredVenue) {
            $receipt = orbitLoopNestedLabel($markdown, 'Proof', 'Verification', 'runtime['.$requiredVenue.']');
            if ($receipt === null) {
                return "Verification runtime receipt is missing for acceptance venue {$requiredVenue}";
            }
            $problem = orbitLoopRuntimeProofProblemForValue($receipt, $requiredVenue, $candidateCommit, $worktree);
            if ($problem !== null) {
                return $problem;
            }
        }
        return null;
    }

    if (orbitLoopStatusHead($runtime) !== 'passed') {
        return "Verification runtime must be passed for acceptance venue {$venue}; current: ".($runtime ?? 'missing');
    }

    $runtime = (string) $runtime;

    if (preg_match('/^passed\s+-\s+(.+)$/is', $runtime, $match) !== 1) {
        return 'Verification runtime must use a structured runtime receipt; remain in PROVE and re-prove the final hop';
    }

    $detail = trim($match[1]);
    $fields = orbitLoopRuntimeProofParseFields($detail);

    if ($fields === null) {
        $deferredProblem = orbitLoopRuntimeProofDeferredFinalHopProblem($detail);

        if ($deferredProblem !== null) {
            return $deferredProblem;
        }

        return 'Verification runtime must use a structured runtime receipt; remain in PROVE and re-prove the final hop';
    }

    $receiptProblem = orbitLoopRuntimeProofReceiptProblem($fields, $venue, $candidateCommit, $worktree);

    if ($receiptProblem !== null) {
        return $receiptProblem;
    }

    foreach (['environment', 'expected', 'observed'] as $narrativeField) {
        $deferredProblem = orbitLoopRuntimeProofDeferredFinalHopProblem($fields[$narrativeField]);

        if ($deferredProblem !== null) {
            return $deferredProblem;
        }
    }

    // After deferred-hop narrative checks so open final-hop language keeps its
    // existing error surface; completed live/production claims still require
    // exact environment=live.
    return orbitLoopRuntimeProofLiveEnvironmentProblem($fields);
}

/** @return list<string> */
function orbitLoopChangedFilesForRuntime(string $worktree): array
{
    try {
        $base = orbitLoopGitValue($worktree, ['rev-parse', 'main']);
        $head = orbitLoopGitValue($worktree, ['rev-parse', 'HEAD']);
        $mergeBase = $base !== null && $head !== null
            ? orbitLoopGitValue($worktree, ['merge-base', $base, $head])
            : null;
        $diff = $mergeBase !== null && $head !== null
            ? orbitLoopGitOutput($worktree, ['diff', '--name-status', '-z', '--diff-filter=ACDMRT', $mergeBase, $head])
            : null;
        return $diff === null ? [] : orbitLoopParseNameStatusDiff($diff);
    } catch (Throwable) {
        return [];
    }
}

function orbitLoopRuntimeProofProblemForValue(string $runtime, string $venue, string $candidateCommit, string $worktree): ?string
{
    $fields = orbitLoopRuntimeProofParseFields(preg_replace('/^passed\s+-\s+/i', '', $runtime) ?? $runtime);
    if ($fields === null) {
        return 'Verification runtime must use a structured runtime receipt; remain in PROVE and re-prove the final hop';
    }
    $problem = orbitLoopRuntimeProofReceiptProblem($fields, $venue, $candidateCommit, $worktree);
    if ($problem !== null) {
        return $problem;
    }
    return null;
}

function orbitLoopRuntimeProofDeferredFinalHopProblem(string $narrative): ?string
{
    $normalized = strtolower($narrative);

    // Zero / negated outcomes are truthful completed counts, not open final hops.
    $allowPatterns = [
        '/\bno\s+failures?\b/',
        '/\bwithout\s+failures?\b/',
        '/\bno\s+failed(?:\s+hops?)?\b/',
        '/\bfailure\s+modes?\s+absent\b/',
        '/\bprevious\s+failures?\b/',
        '/\bexpected\s+failures?\b/',
        '/\bfailures?\s+repaired\b/',
        '/\brepaired\s+(?:the\s+)?(?:previous\s+)?failures?\b/',
        '/\b(?:0|zero|no|none|nothing)\s+(?:failed|failures|skipped|pending|outstanding)\b/',
        '/\b(?:no|none|nothing)\s+outstanding\b/',
        '/\b\d+\s+skipped\b/',
        '/\bcompleted\s+without\s+failures?\b/',
    ];

    foreach ($allowPatterns as $pattern) {
        $normalized = preg_replace($pattern, ' ', $normalized) ?? $normalized;
    }

    $blockedPatterns = [
        // Protect compound product names such as deferred-queue.
        '/\bdeferred\b(?![\w-])/',
        '/\bdeferral\b/',
        '/\bdefer(?:s|ring)?\b(?![\w-])/',
        '/\bpost-land\b(?![\w-])/',
        '/\bpost\s+land(?:ing)?\b/',
        '/\bafter\s+land(?:ing)?\b/',
        '/\bpost-merge\b(?![\w-])/',
        '/\bpost\s+merge\b/',
        '/\bafter\s+merge\b/',
        '/\bremains\s+required\b/',
        '/\bstill\s+required\b/',
        '/\bfollow-up\s+closure\s+proof\b/',
        '/\bclosure\s+proof\b/',
        '/\bclosure\s+is\s+a\s+deferral\b/',
        // Exclude / incomplete only when they signal an open final hop or proof.
        '/\b(?:was\s+)?exclud(?:e|es|ed)\b(?:\s+the\s+(?:decisive|final)\s+hop)?/',
        '/\bwe\s+exclud(?:e|es|ed)\b/',
        '/\bfinal\s+hop\s+(?:excluded|skipped|not\s+reached|not\s+completed|incomplete)\b/',
        '/\b(?:final|decisive|live)\s+hop\s+(?:is\s+)?(?:incomplete|not\s+completed|not\s+reached)\b/',
        '/\b(?:live\s+)?(?:verification|proof)\s+(?:is\s+)?(?:incomplete|outstanding|skipped)\b/',
        '/\bhop\s+skipped\b/',
        '/\bskipped\s+the\s+(?:live|final|decisive)\s+hop\b/',
        '/\bre-proof\s+follows\b/',
        '/\bproof\s+follows\b/',
        '/\bre-proof\s+after\b/',
        '/\bwill\s+(?:confirm|re-run)\b/',
        '/\bto\s+be\s+run\s+later\b/',
        '/\boutstanding\b/',
        '/\bfailed\b/',
        '/\bfailure\b/',
        '/\bpending\b/',
    ];

    foreach ($blockedPatterns as $pattern) {
        if (preg_match($pattern, $normalized) === 1) {
            return 'Verification runtime claims passed while the decisive final hop failed, was excluded, remains required, or is deferred; remain in PROVE and re-prove the final hop';
        }
    }

    return null;
}

/**
 * @param  array<string, string>  $fields
 */
function orbitLoopRuntimeProofReceiptProblem(
    array $fields,
    string $venue,
    string $candidateCommit,
    string $worktree,
): ?string {
    foreach (array_keys($fields) as $key) {
        if (! in_array($key, ORBIT_LOOP_RUNTIME_RECEIPT_ALLOWED_KEYS, true)) {
            return 'Verification runtime must use a structured runtime receipt; remain in PROVE and re-prove the final hop';
        }
    }

    $required = ['candidate', 'venue', 'environment', 'expected', 'observed', 'result', 'evidence'];

    foreach ($required as $key) {
        if (! array_key_exists($key, $fields) || trim($fields[$key]) === '') {
            return "Verification runtime structured receipt is missing {$key}=; remain in PROVE and re-prove the final hop";
        }
    }

    $hasTarget = array_key_exists('target', $fields) && trim($fields['target']) !== '';
    $hasCommand = array_key_exists('command', $fields) && trim($fields['command']) !== '';

    if ($hasTarget === $hasCommand) {
        return 'Verification runtime structured receipt requires exactly one of target= or command=; remain in PROVE and re-prove the final hop';
    }

    if (preg_match('/^[0-9a-f]{40}$/', $fields['candidate']) !== 1) {
        return 'Verification runtime structured receipt candidate= must be the exact 40-character candidate commit; remain in PROVE and re-prove the final hop';
    }

    if (! hash_equals($candidateCommit, $fields['candidate'])) {
        return 'Verification runtime structured receipt candidate= must equal the candidate commit; remain in PROVE and re-prove the final hop';
    }

    if ($fields['venue'] !== $venue) {
        return "Verification runtime structured receipt venue= must equal acceptance venue {$venue}; remain in PROVE and re-prove the final hop";
    }

    if ($fields['result'] !== 'passed') {
        return 'Verification runtime structured receipt result= must be passed; remain in PROVE and re-prove the final hop';
    }

    return orbitLoopRuntimeProofEvidenceProblem($worktree, $fields['evidence']);
}

/**
 * Non-automated receipts that claim a live/production surface must set
 * environment=live exactly. Scan only environment/target/command/expected/observed.
 *
 * @param  array<string, string>  $fields
 */
function orbitLoopRuntimeProofLiveEnvironmentProblem(array $fields): ?string
{
    $claimFields = ['environment', 'target', 'command', 'expected', 'observed'];
    $claimsLiveOrProduction = false;

    foreach ($claimFields as $key) {
        if (! array_key_exists($key, $fields)) {
            continue;
        }

        if (preg_match('/\b(?:live|production)\b/i', $fields[$key]) === 1) {
            $claimsLiveOrProduction = true;
            break;
        }
    }

    if (! $claimsLiveOrProduction) {
        return null;
    }

    if ($fields['environment'] === 'live') {
        return null;
    }

    return 'Verification runtime structured receipt that claims a live/production surface requires exact environment=live; remain in PROVE and re-prove the final hop';
}

function orbitLoopRuntimeProofEvidenceProblem(string $worktree, string $evidenceField): ?string
{
    if (
        preg_match(
            '/^`(\.orbit\/(?:evidence|quality-gates)\/(?:[A-Za-z0-9._-]+\/)*[A-Za-z0-9._-]*[A-Za-z0-9_-])`$/',
            $evidenceField,
            $match,
        ) !== 1
    ) {
        return 'Verification runtime structured receipt evidence= must be one exact inline-code path under .orbit/evidence/ or .orbit/quality-gates/; remain in PROVE and re-prove the final hop';
    }

    $reference = $match[1];
    $relative = substr($reference, strlen('.orbit/'));
    $segments = explode('/', $relative);

    if (
        ! in_array($segments[0] ?? '', ['evidence', 'quality-gates'], true)
        || count($segments) < 2
        || in_array('', $segments, true)
        || in_array('.', $segments, true)
        || in_array('..', $segments, true)
    ) {
        return 'Verification runtime structured receipt evidence= path is unsafe; remain in PROVE and re-prove the final hop';
    }

    $orbitDir = rtrim($worktree, '/').'/.orbit';

    if (is_link($orbitDir)) {
        return 'Verification runtime structured receipt evidence= must not traverse a symlink; remain in PROVE and re-prove the final hop';
    }

    $canonicalWorktree = realpath($worktree);
    $canonicalOrbit = realpath($orbitDir);

    if (
        $canonicalWorktree === false
        || $canonicalOrbit === false
        || $canonicalOrbit !== rtrim($canonicalWorktree, '/').'/.orbit'
    ) {
        return 'Verification runtime structured receipt evidence= escapes the active .orbit directory; remain in PROVE and re-prove the final hop';
    }

    $current = $orbitDir;

    foreach ($segments as $segment) {
        $current .= '/'.$segment;

        if (is_link($current)) {
            return 'Verification runtime structured receipt evidence= must not traverse a symlink; remain in PROVE and re-prove the final hop';
        }
    }

    if (! is_file($current) || is_link($current)) {
        return 'Verification runtime structured receipt evidence= must name an existing regular file under the worktree; remain in PROVE and re-prove the final hop';
    }

    $canonicalSource = realpath($current);

    if (
        $canonicalSource === false
        || ! str_starts_with($canonicalSource, rtrim($canonicalOrbit, '/').'/')
    ) {
        return 'Verification runtime structured receipt evidence= escapes the active .orbit directory; remain in PROVE and re-prove the final hop';
    }

    return null;
}

/**
 * @return array<string, string>|null
 */
function orbitLoopRuntimeProofParseFields(string $detail): ?array
{
    $fields = [];
    $parts = preg_split('/;\s*/', $detail) ?: [];

    foreach ($parts as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        if (preg_match('/^([a-z][a-z0-9_]*)=(.*)$/s', $part, $match) !== 1) {
            return null;
        }

        $key = $match[1];

        if (array_key_exists($key, $fields)) {
            return null;
        }

        $fields[$key] = trim($match[2]);
    }

    return $fields === [] ? null : $fields;
}

function orbitLoopAcceptedIdentityProblem(string $markdown, string $featureTip, string $mainTip): ?string
{
    $state = orbitLoopStatusHead(orbitLoopLabel($markdown, 'Status', 'State'));

    if (! in_array($state, ['accepted', 'land'], true)) {
        return 'State must be accepted or land; current: '.($state ?? 'missing');
    }

    $reviewProblem = orbitLoopReviewedIdentityProblem($markdown, $featureTip);

    if ($reviewProblem !== null) {
        return $reviewProblem;
    }

    if (orbitLoopStatusHead(orbitLoopLabel($markdown, 'Proof', 'Acceptance')) !== 'accepted') {
        return 'Acceptance must be accepted';
    }

    $acceptedFeature = orbitLoopLabel($markdown, 'Proof', 'Accepted feature tip');
    $acceptedMain = orbitLoopLabel($markdown, 'Proof', 'Accepted main tip');

    if ($acceptedFeature === null || ! hash_equals($featureTip, $acceptedFeature)) {
        return 'accepted feature tip does not equal the feature branch tip';
    }

    if ($acceptedMain === null || ! hash_equals($mainTip, $acceptedMain)) {
        return 'main advanced after acceptance';
    }

    return null;
}

function orbitLoopAcceptanceVenue(array $changedFiles): string
{
    $venues = orbitLoopAcceptanceVenues($changedFiles);

    if (count($venues) !== 1) {
        throw new RuntimeException(
            'candidate diff requires more than one orthogonal non-automated acceptance venue ('
            .implode(', ', $venues)
            .'); use independently validated receipts for each venue',
        );
    }

    return $venues[0];
}

/** @param list<string> $changedFiles @return list<string> */
function orbitLoopAcceptanceVenues(array $changedFiles): array
{
    $venues = [];

    foreach ($changedFiles as $path) {
        $path = (string) $path;

        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        if ($path === '' || orbitLoopPathIsAutomationOnly($path)) {
            continue;
        }

        $venue = 'retained-incus';

        if (str_starts_with($path, 'apps/macos/')) {
            $venue = 'host-macos';
        } elseif (
            str_starts_with($path, 'apps/gateway/resources/')
            || str_starts_with($path, 'apps/docs/resources/')
        ) {
            $venue = 'browser';
        }

        $venues[$venue] = $venue;
    }

    $venues = array_values($venues);

    if ($venues === []) {
        return ['automated'];
    }
    sort($venues, SORT_STRING);
    return $venues;
}

function orbitLoopPathIsAutomationOnly(string $path): bool
{
    return (
        str_ends_with($path, '.md')
        || str_contains($path, '/tests/')
        || str_starts_with($path, 'tests/')
        || str_starts_with($path, 'bin/')
        || str_starts_with($path, '.agents/')
        || str_starts_with($path, '.claude/')
        || str_starts_with($path, '.codex/')
        || str_starts_with($path, '.github/')
        // Repository-root static documentation HTML only (docs/**.html). Does not
        // reclassify apps/docs runtime, resources, or other product frontend paths.
        || (str_starts_with($path, 'docs/') && str_ends_with($path, '.html'))
        // TypeScript SDK is repository packaging only. PHP packages/sdk is a
        // production require of CLI/gateway and stays retained-incus by default.
        // packages/core/src remains retained-incus.
        || str_starts_with($path, 'packages/sdk-typescript/')
        // Narrow docs automation surfaces only. Do not classify all of apps/docs:
        // apps/docs/resources/** must stay browser; other docs/runtime source stays retained-incus.
        || str_starts_with($path, 'apps/docs/app/Librarian/')
        || str_starts_with($path, 'apps/docs/config/librarian-command-docs/')
        || str_starts_with($path, 'apps/docs/content/generated/')
        || in_array(
            $path,
            [
                '.orbit/sessions/index.json',
                'composer.json',
                'composer.lock',
                'LOOP.md.example',
                'AGENTS.md',
                'AGENT_FAST_PATH.md',
                'HARNESS.md',
            ],
            true,
        )
    );
}

/**
 * Fail-closed exact proof-route derivation shared by route/ready/accept.
 *
 * @return array{
 *     candidate: string,
 *     base: string,
 *     base_tip: string,
 *     merge_base: string,
 *     changed_files: list<string>,
 *     venue: string,
 *     venues: list<string>
 * }
 */
function orbitLoopExactProofRoute(string $cwd, string $base = 'main', string $head = 'HEAD'): array
{
    $candidate = orbitLoopGitValue($cwd, ['rev-parse', $head]);
    $baseTip = orbitLoopGitValue($cwd, ['rev-parse', $base]);
    $mergeBase = orbitLoopGitValue($cwd, ['merge-base', $base, $head]);

    if ($candidate === null || $candidate === '' || $baseTip === null || $baseTip === '' || $mergeBase === null || $mergeBase === '') {
        throw new RuntimeException('unable to derive exact proof route');
    }

    $output = orbitLoopGitOutput($cwd, [
        'diff',
        '--name-status',
        '-z',
        '--diff-filter=ACDMRT',
        $mergeBase,
        $head,
    ]);

    if ($output === null) {
        throw new RuntimeException('unable to derive exact proof route');
    }

    $changedFiles = orbitLoopParseNameStatusDiff($output);

    return [
        'candidate' => $candidate,
        'base' => $base,
        'base_tip' => $baseTip,
        'merge_base' => $mergeBase,
        'changed_files' => $changedFiles,
        'venue' => count(orbitLoopAcceptanceVenues($changedFiles)) === 1
            ? orbitLoopAcceptanceVenues($changedFiles)[0]
            : implode(',', orbitLoopAcceptanceVenues($changedFiles)),
        'venues' => orbitLoopAcceptanceVenues($changedFiles),
    ];
}

/**
 * @return list<string>
 */
function orbitLoopParseNameStatusDiff(string $output): array
{
    $tokens = explode("\0", $output);
    $paths = [];

    for ($index = 0; $index < count($tokens);) {
        $status = $tokens[$index++] ?? '';

        if ($status === '') {
            continue;
        }

        $source = $tokens[$index++] ?? '';

        if ($source !== '') {
            $paths[] = $source;
        }

        if (in_array($status[0] ?? '', ['C', 'R'], true)) {
            $destination = $tokens[$index++] ?? '';

            if ($destination !== '') {
                $paths[] = $destination;
            }
        }
    }

    return array_values(array_unique($paths));
}

/**
 * Nullable legacy helper for non-acceptance callers. Prefer
 * orbitLoopExactProofRoute() for acceptance/route fail-closed derivation.
 *
 * @return list<string>
 */
function orbitLoopChangedFiles(string $cwd, string $head = 'HEAD', string $base = 'main'): array
{
    try {
        return orbitLoopExactProofRoute($cwd, $base, $head)['changed_files'];
    } catch (RuntimeException) {
        return [];
    }
}

function orbitLoopGitValue(string $cwd, array $arguments): ?string
{
    $output = orbitLoopGitOutput($cwd, $arguments);

    return $output === null ? null : trim($output);
}

function orbitLoopGitOutput(string $cwd, array $arguments): ?string
{
    $command = ['git', ...$arguments];
    $descriptorSpec = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);

    if (! is_resource($process)) {
        return null;
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return $exitCode === 0 ? (string) $stdout : null;
}
