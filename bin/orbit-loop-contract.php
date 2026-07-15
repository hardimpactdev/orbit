<?php

declare(strict_types=1);

const ORBIT_LOOP_STATES = ['frame', 'build', 'prove', 'accept', 'accepted', 'land', 'blocked'];

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
        '~\.orbit/(?:evidence|quality-gates)/~',
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

        $openingDelimiterIsExact = $markerOffset > 0
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
            '~^\.orbit/(?:evidence|quality-gates)/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]*[A-Za-z0-9_-]~',
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
    return in_array($actual, ORBIT_LOOP_ACCEPTANCE_VENUES, true)
        && ($actual === $required || $required === 'automated');
}

function orbitLoopRuntimeProofProblem(string $markdown, string $venue): ?string
{
    if ($venue === 'automated') {
        return null;
    }

    $runtime = orbitLoopNestedLabel($markdown, 'Proof', 'Verification', 'runtime');

    if (orbitLoopStatusHead($runtime) !== 'passed') {
        return "Verification runtime must be passed for acceptance venue {$venue}; current: ".($runtime ?? 'missing');
    }

    return null;
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
    $venue = 'automated';

    foreach ($changedFiles as $path) {
        $path = ltrim((string) $path, './');

        if ($path === '' || orbitLoopPathIsAutomationOnly($path)) {
            continue;
        }

        if (str_starts_with($path, 'apps/macos/')) {
            return 'host-macos';
        }

        if (
            str_starts_with($path, 'apps/gateway/resources/')
            || str_starts_with($path, 'apps/docs/resources/')
        ) {
            $venue = orbitLoopStrongerVenue($venue, 'browser');

            continue;
        }

        $venue = orbitLoopStrongerVenue($venue, 'retained-incus');
    }

    return $venue;
}

function orbitLoopPathIsAutomationOnly(string $path): bool
{
    return (
        str_ends_with($path, '.md')
        || str_contains($path, '/tests/')
        || str_starts_with($path, 'tests/')
        || str_starts_with($path, 'bin/')
        || str_starts_with($path, '.agents/')
        || str_starts_with($path, '.github/')
        || in_array(
            $path,
            [
                'orbit/sessions/index.json',
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

function orbitLoopStrongerVenue(string $left, string $right): string
{
    $strength = [
        'automated' => 0,
        'retained-incus' => 1,
        'browser' => 2,
        'host-macos' => 3,
    ];

    return ($strength[$right] ?? -1) > ($strength[$left] ?? -1) ? $right : $left;
}

/**
 * @return list<string>
 */
function orbitLoopChangedFiles(string $cwd, string $head = 'HEAD', string $base = 'main'): array
{
    $mergeBase = orbitLoopGitValue($cwd, ['merge-base', $base, $head]);

    if ($mergeBase === null) {
        return [];
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
        return [];
    }

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
