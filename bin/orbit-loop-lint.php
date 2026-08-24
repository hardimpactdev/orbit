<?php

declare(strict_types=1);

require_once __DIR__.'/orbit-loop-contract.php';

const LOOP_OUTCOME_ENUM = ['complete', 'blocked', 'complete + loop improvement'];

const PLACEHOLDER_TOKENS = ['pending', 'tbd', 'todo', 'not yet'];

/**
 * @return list<string>
 */
function compact_loop_problems(string $contents, ?string $path = null): array
{
    $problems = [];

    try {
        orbitLoopSlices($contents);

        if ($path !== null) {
            $loopPath = $path;
            $worktree = dirname(dirname($loopPath));
        } else {
            $worktreeMatches = [];
            if (preg_match_all('/^- Worktree: (\/.+)$/m', $contents, $worktreeMatches) !== 1) {
                throw new RuntimeException('active compact loop requires an absolute Worktree path for Slices validation');
            }

            $worktree = $worktreeMatches[1][0];
            $worktreeStat = @lstat($worktree);
            if (
                $worktreeStat === false
                || is_link($worktree)
                || ($worktreeStat['mode'] & 0170000) !== 0040000
            ) {
                throw new RuntimeException('active compact loop Worktree path is unsafe');
            }

            $loopPath = $worktree.'/.orbit/loop.md';
            $loopStat = @lstat($loopPath);
            if (
                $loopStat === false
                || is_link($loopPath)
                || ($loopStat['mode'] & 0170000) !== 0100000
            ) {
                throw new RuntimeException('active compact loop Worktree packet is unsafe');
            }

            $storedContents = orbitLoopReadSlicePacket($loopPath, '.orbit/loop.md');
            if ($storedContents !== $contents) {
                throw new RuntimeException('active compact loop Worktree packet does not match checked contents');
            }
        }

        $featureTip = orbitLoopGitValue($worktree, ['rev-parse', 'HEAD']) ?? str_repeat('0', 40);
        $sliceProblems = orbitLoopSliceFinalizationProblems($contents, $worktree, $featureTip);
        foreach ($sliceProblems as $sliceProblem) {
            $problems[] = 'Slices: '.$sliceProblem;
        }
    } catch (Throwable $exception) {
        $problems[] = 'Slices: '.$exception->getMessage();
    }

    foreach (['Goal', 'Scope', 'Proof', 'Status', 'Feedback'] as $section) {
        if (orbitLoopSection($contents, $section) === null) {
            $problems[] = "is missing `{$section}`";
        }
    }

    $scopeFramingProblem = orbitLoopScopeFramingProblem($contents);

    if ($scopeFramingProblem !== null) {
        $problems[] = $scopeFramingProblem;
    }

    if (preg_match('/<[^>\n]+>/', $contents, $placeholder) === 1) {
        $problems[] = 'contains an unexpanded template placeholder: '.$placeholder[0];
    }

    $state = orbitLoopStatusHead(orbitLoopLabel($contents, 'Status', 'State'));
    if ($state === null || ! in_array($state, ['accepted', 'land'], true)) {
        $problems[] = 'State must be accepted or land; current: '.($state ?? 'missing');
    }

    $blocker = strtolower((string) orbitLoopLabel($contents, 'Status', 'Blocker'));
    if ($blocker !== 'none') {
        $problems[] = 'Blocker must be none; current: '.($blocker === '' ? 'missing' : $blocker);
    }

    $review = orbitLoopLabel($contents, 'Proof', 'Review');
    if (! orbitLoopReviewPassed($review)) {
        $problems[] = 'Review must be passed; current: '.($review ?? 'missing');
    } elseif (orbitLoopReviewHumanJudgment($review) === null) {
        $problems[] = 'Review must record human-judgment=required or human-judgment=not-required';
    }

    $blastRadiusProblem = orbitLoopBlastRadiusProblem($contents);

    if ($blastRadiusProblem !== null) {
        $problems[] = $blastRadiusProblem;
    }

    $reviewedTip = orbitLoopLabel($contents, 'Proof', 'Reviewed feature tip');
    if ($reviewedTip === null || preg_match('/^[0-9a-f]{40}$/', $reviewedTip) !== 1) {
        $problems[] =
            'Reviewed feature tip must be an exact 40-character commit id; current: '.($reviewedTip ?? 'missing');
    }

    $venue = orbitLoopStatusHead(orbitLoopLabel($contents, 'Proof', 'Acceptance venue'));
    $venuesLabel = orbitLoopLabel($contents, 'Proof', 'Acceptance venues');
    $venues = $venue !== null
        ? [$venue]
        : array_values(array_filter(array_map('trim', explode(',', (string) $venuesLabel))));
    if ($venues === [] || array_diff($venues, ORBIT_LOOP_ACCEPTANCE_VENUES) !== [] || count($venues) !== count(array_unique($venues))) {
        $problems[] =
            'Acceptance venue(s) must be automated, retained-incus, browser, or host-macos; current: '
            .($venue ?? $venuesLabel ?? 'missing');
    }

    $acceptance = orbitLoopStatusHead(orbitLoopLabel($contents, 'Proof', 'Acceptance'));
    if ($acceptance !== 'accepted') {
        $problems[] = 'Acceptance must be accepted; current: '.($acceptance ?? 'missing');
    }

    foreach (['Accepted feature tip', 'Accepted main tip'] as $label) {
        $value = orbitLoopLabel($contents, 'Proof', $label);
        if ($value === null || preg_match('/^[0-9a-f]{40}$/', $value) !== 1) {
            $problems[] = "{$label} must be an exact 40-character commit id; current: ".($value ?? 'missing');
        }
    }

    foreach (['focused', 'broader', 'runtime'] as $label) {
        $value = orbitLoopNestedLabel($contents, 'Proof', 'Verification', $label);
        $head = orbitLoopStatusHead($value);

        if ($head === null || ! in_array($head, ['passed', 'not applicable'], true)) {
            $problems[] = "Verification {$label} must be passed or not applicable; current: ".($value ?? 'missing');
        }
    }

    $feedback = orbitLoopLabel($contents, 'Feedback', 'Events');
    if ($feedback === null || ! str_contains($feedback, '.orbit/feedback.jsonl')) {
        $problems[] = 'Feedback Events must point to .orbit/feedback.jsonl';
    }

    $sessionProblem = loop_session_header_problem($contents);

    if ($sessionProblem !== null) {
        $problems[] = $sessionProblem;
    }

    return $problems;
}

function run_compact_lint(string $path, string $contents): int
{
    $problems = compact_loop_problems($contents, $path);

    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(STDOUT, "BLOCKED: {$problem}\n");
        }

        return BLOCK_EXIT_CODE;
    }

    fwrite(STDOUT, "PASS: {$path} compact feature loop is ready for finalization\n");

    return 0;
}

/**
 * Validate only the Final Distillation packet shape of a loop.md file without
 * requiring a git action, so orchestrators can dry-run before merge time.
 */
function run_lint(string $path): int
{
    if (! is_file($path)) {
        fwrite(STDOUT, "BLOCKED: no loop packet found at {$path}\n");

        return BLOCK_EXIT_CODE;
    }

    $contents = (string) file_get_contents($path);

    if (orbitLoopIsCompact($contents)) {
        return run_compact_lint($path, $contents);
    }

    $section = markdown_section($contents, 'Final Distillation');

    if ($section === null) {
        fwrite(STDOUT, "BLOCKED: {$path} has no `Final Distillation` section\n");

        return BLOCK_EXIT_CODE;
    }

    $problems = [];
    $warnings = [];

    $placeholderLine = section_placeholder_line($section);

    if ($placeholderLine !== null) {
        $problems[] = "template placeholders remain in `Final Distillation`: {$placeholderLine}";
    }

    $loopOutcome = loop_outcome($section);

    if ($loopOutcome === null) {
        $problems[] = 'missing `- Loop outcome:` value';
    } elseif (! in_array(normalize_outcome_value($loopOutcome), LOOP_OUTCOME_ENUM, true)) {
        $problems[] = "loop outcome must be exactly one of: complete | blocked | complete + loop improvement; found: {$loopOutcome}";
    }

    $verificationValues = markdown_list_values($section, ['Required verification']);

    if ($verificationValues === []) {
        $problems[] = 'missing `Required verification` rows';
    } else {
        foreach (['Retained topology proof', '`composer quality-check`'] as $requiredLabel) {
            if (! has_required_verification_row($verificationValues, $requiredLabel)) {
                $problems[] = "missing {$requiredLabel} verification row";
            }
        }

        foreach ($verificationValues as $value) {
            if (is_placeholder_row_value($value)) {
                $problems[] = "placeholder value in required verification row: {$value}";
            }
        }

        if ($loopOutcome !== null && is_mergeable_loop_outcome($loopOutcome)) {
            foreach ($verificationValues as $value) {
                if (is_blocked_verification_value($value)) {
                    $problems[] = "loop outcome is complete while required verification is blocked: {$value}";
                }
            }
        }
    }

    $analyzerProblem = fresh_analyzer_problem($section);

    if ($analyzerProblem !== null) {
        $problems[] = $analyzerProblem;
    }

    $deferredAnalyzer = fresh_analyzer_deferred_value($section);

    if ($deferredAnalyzer !== null) {
        $warnings[] = "Fresh analyzer deferred: {$deferredAnalyzer}";
    }

    $signalProblem = signal_outcome_problem($section);

    if ($signalProblem !== null) {
        $problems[] = $signalProblem;
    }

    $reconstructionWarning = reconstruction_smell_warning($contents, $section);

    if ($reconstructionWarning !== null) {
        $warnings[] = $reconstructionWarning;
    }

    $agentSessionsDirs = [dirname($path).'/agent-sessions'];
    $branchName = branch_name_from_loop($contents);

    if ($branchName !== null) {
        $worktreeRoot = dirname(dirname($path));

        foreach (matching_session_archive_dirs($worktreeRoot, $branchName) as $archiveDir) {
            $agentSessionsDirs[] = $archiveDir.'/agent-sessions';
        }
    }

    $captureHealthProblem = capture_health_problem_for_sources($path, $agentSessionsDirs);

    if ($captureHealthProblem !== null) {
        $problems[] = $captureHealthProblem;
    }

    $sessionProblem = loop_session_header_problem($contents);

    if ($sessionProblem !== null) {
        $problems[] = $sessionProblem;
    }

    foreach ($warnings as $warning) {
        fwrite(STDOUT, "WARNING: {$warning}\n");
    }

    if ($problems !== []) {
        foreach ($problems as $problem) {
            fwrite(STDOUT, "BLOCKED: {$problem}\n");
        }

        return BLOCK_EXIT_CODE;
    }

    fwrite(STDOUT, "PASS: {$path} Final Distillation packet shape is valid\n");

    return 0;
}

function branch_name_from_loop(string $contents): ?string
{
    return orbitLoopTopLabel($contents, 'Branch');
}

function loop_session_header_problem(string $contents): ?string
{
    $session = orbitLoopTopLabel($contents, 'Session');

    if ($session === null || $session === '') {
        return null;
    }

    if (preg_match('/^feat-[a-z0-9][a-z0-9-]*$/', $session) !== 1) {
        return 'Session must match feat-<slug> (lowercase, digits, hyphens); current: '.$session;
    }

    return null;
}

function markdown_section(string $contents, string $heading): ?string
{
    $lines = preg_split('/\R/', $contents);
    $capture = false;
    $section = [];

    foreach ($lines as $line) {
        if (preg_match('/^##\s+'.preg_quote($heading, '/').'\s*$/', $line) === 1) {
            $capture = true;

            continue;
        }

        if ($capture && preg_match('/^##\s+/', $line) === 1) {
            break;
        }

        if ($capture) {
            $section[] = $line;
        }
    }

    if (! $capture) {
        return null;
    }

    return trim(implode("\n", $section));
}

function section_placeholder_line(string $section): ?string
{
    foreach (preg_split('/\R/', $section) as $line) {
        if (preg_match('/<[^>\n]+>/', $line) === 1) {
            return trim($line);
        }
    }

    return null;
}

function loop_outcome(string $section): ?string
{
    $outcomes = markdown_list_values($section, ['Loop outcome']);

    return $outcomes[0] ?? null;
}

function is_mergeable_loop_outcome(string $value): bool
{
    $normalized = normalize_outcome_value($value);

    return in_array($normalized, ['complete', 'complete + loop improvement'], true);
}

/**
 * @param  list<string>  $values
 */
function has_required_verification_row(array $values, string $requiredLabel): bool
{
    return required_verification_row($values, $requiredLabel) !== null;
}

/**
 * @param  list<string>  $values
 */
function required_verification_row(array $values, string $requiredLabel): ?string
{
    $normalizedRequiredLabel = normalize_markdown_label($requiredLabel);

    foreach ($values as $value) {
        if (preg_match('/^([^:]+):/', $value, $matches) !== 1) {
            continue;
        }

        if (normalize_markdown_label($matches[1]) === $normalizedRequiredLabel) {
            return $value;
        }
    }

    return null;
}

function is_blocked_verification_value(string $value): bool
{
    $normalized = strtolower(trim($value));

    return (
        preg_match('/^(?:[^:]+:\s*)?(?:blocked|pending|missing|skipped|deferred|unresolved|not\s+run)\b/', $normalized)
        === 1
    );
}

function is_placeholder_row_value(string $value): bool
{
    $status = preg_match('/^[^:]+:\s*(.*)$/', $value, $matches) === 1 ? $matches[1] : $value;

    return is_placeholder_text($status);
}

function is_placeholder_text(string $value): bool
{
    if (preg_match('/<[^>\n]+>/', $value) === 1) {
        return true;
    }

    return in_array(normalize_outcome_value($value), PLACEHOLDER_TOKENS, true);
}

function is_passed_verification_value(string $value): bool
{
    $normalized = strtolower(trim($value));

    return preg_match('/^(?:[^:]+:\s*)?passed\b/', $normalized) === 1;
}

function is_release_acceptance_topology_deferral(string $retainedTopologyProof, string $section): bool
{
    $normalizedProof = strtolower(trim($retainedTopologyProof));

    if (preg_match('/^(?:retained topology proof:\s*)?not\s+applicable\b/', $normalizedProof) !== 1) {
        return false;
    }

    $normalizedSection = strtolower($section);

    foreach (['main-based rc', 'release acceptance', 'update:all', '--node='] as $requiredPhrase) {
        if (! str_contains($normalizedSection, $requiredPhrase)) {
            return false;
        }
    }

    return true;
}

/**
 * @return list<string>
 */
function final_outcome_values(string $section): array
{
    return markdown_list_values($section, [
        'Accepted durable updates',
        'Rejected or already-covered signals',
        'Deferred follow-ups',
        'No-new-signal rationale',
    ]);
}

function signal_outcome_problem(string $section): ?string
{
    $values = final_outcome_values($section);

    foreach ($values as $value) {
        if (is_placeholder_text($value)) {
            return "records a placeholder signal outcome instead of a real value: {$value}";
        }
    }

    foreach ($values as $value) {
        if (is_meaningful_outcome($value)) {
            return null;
        }
    }

    return 'does not record an accepted update, rejected/already-covered signal, deferred follow-up, or no-new-signal rationale';
}

function fresh_analyzer_problem(string $section): ?string
{
    if (! markdown_label_exists($section, 'Fresh analyzer')) {
        return 'has no `- Fresh analyzer:` row in `Final Distillation`; record the analyzer verdict, `not used - <rationale>` for a compact loop, or `deferred - <reason>` when analyzer infrastructure failed';
    }

    foreach (markdown_list_values($section, ['Fresh analyzer']) as $value) {
        if (trim($value) !== '') {
            return null;
        }
    }

    return 'has an empty `- Fresh analyzer:` row in `Final Distillation`; record the analyzer verdict, `not used - <rationale>` for a compact loop, or `deferred - <reason>` when analyzer infrastructure failed';
}

function fresh_analyzer_deferred_value(string $section): ?string
{
    foreach (markdown_list_values($section, ['Fresh analyzer']) as $value) {
        $normalized = strtolower(trim($value));
        $afterLabel = preg_match('/^[^:]+:\s*(.*)$/', $value, $matches) === 1
            ? strtolower(trim($matches[1]))
            : $normalized;

        if (str_starts_with($normalized, 'deferred') || str_starts_with($afterLabel, 'deferred')) {
            return $value;
        }
    }

    return null;
}

function reconstruction_smell_warning(string $contents, string $section): ?string
{
    if (! candidate_signals_are_empty($contents)) {
        return null;
    }

    foreach (markdown_list_values($section, ['Accepted durable updates']) as $value) {
        if (is_meaningful_outcome($value)) {
            return (
                'Accepted durable updates are recorded while `Candidate Signals While Working` is empty or none; '
                .'signals look reconstructed post-hoc instead of captured during the loop'
            );
        }
    }

    return null;
}

function candidate_signals_are_empty(string $contents): bool
{
    $section = markdown_section($contents, 'Candidate Signals While Working');

    if ($section === null) {
        return true;
    }

    foreach (preg_split('/\R/', $section) as $line) {
        if (preg_match('/^\s*-\s+(.+)$/', $line, $matches) !== 1) {
            continue;
        }

        $value = trim($matches[1]);

        if ($value === '' || preg_match('/<[^>\n]+>/', $value) === 1) {
            continue;
        }

        if (in_array(normalize_outcome_value($value), ['none', 'n/a', 'na', 'not applicable'], true)) {
            continue;
        }

        return false;
    }

    return true;
}

/**
 * @param  list<string>  $labels
 * @return list<string>
 */
function markdown_list_values(string $section, array $labels): array
{
    $values = [];
    $activeLabel = null;

    foreach (preg_split('/\R/', $section) as $line) {
        if (preg_match('/^-\s+([^:]+):\s*(.*)$/', $line, $matches) === 1) {
            $label = $matches[1];
            $activeLabel = in_array($label, $labels, true) ? $label : null;

            if ($activeLabel !== null && trim($matches[2]) !== '') {
                $values[] = trim($matches[2]);
            }

            continue;
        }

        if ($activeLabel !== null && preg_match('/^\s+-\s+(.+)$/', $line, $matches) === 1) {
            $values[] = trim($matches[1]);
        }
    }

    return $values;
}

function markdown_label_exists(string $section, string $label): bool
{
    foreach (preg_split('/\R/', $section) as $line) {
        if (preg_match('/^-\s+'.preg_quote($label, '/').':/', $line) === 1) {
            return true;
        }
    }

    return false;
}

function is_meaningful_outcome(string $value): bool
{
    $normalized = normalize_outcome_value($value);

    if ($normalized === '') {
        return false;
    }

    return ! in_array(
        $normalized,
        ['none', 'n/a', 'na', 'not applicable', 'pending', 'todo', 'tbd', 'not yet', 'blocked'],
        true,
    );
}

function normalize_outcome_value(string $value): string
{
    return strtolower(trim($value, " \t\n\r\0\x0B`.'"));
}

function normalize_markdown_label(string $value): string
{
    return strtolower(trim($value, " \t\n\r\0\x0B`.'"));
}
