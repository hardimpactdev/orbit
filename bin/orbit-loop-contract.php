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
        // Narrow docs automation surfaces only. Do not classify all of apps/docs:
        // apps/docs/resources/** must stay browser; other docs/runtime source stays retained-incus.
        || str_starts_with($path, 'apps/docs/app/Librarian/')
        || str_starts_with($path, 'apps/docs/config/librarian-command-docs/')
        || str_starts_with($path, 'apps/docs/content/generated/')
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
