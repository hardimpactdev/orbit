<?php

declare(strict_types=1);

/**
 * @return array{ok: bool, subject: string, reason: string, warnings: list<string>}
 */
function check_merge(string $root, array $targets): array
{
    if (in_array('__orbit_git_context_override__', $targets, true)) {
        return block_result(
            'merge',
            'git repository context overrides are not accepted at the landing boundary; run the wrapper from the target checkout so one linked local branch can be verified',
        );
    }

    if (count($targets) !== 1) {
        return block_result(
            'merge',
            'git merge requires exactly one merge head naming a linked local branch; found '.count($targets),
        );
    }

    $target = $targets[0];
    $symbolic = run_git($root, ['rev-parse', '--symbolic-full-name', '--verify', $target]);
    $symbolicRef = trim($symbolic['stdout']);

    if ($symbolic['exit_code'] !== 0 || ! str_starts_with($symbolicRef, 'refs/heads/')) {
        return block_result(
            $target,
            "merge head `{$target}` does not name a linked local branch; raw commit ids, remote refs, absent branches, and revision expressions are not accepted",
        );
    }

    $branch = substr($symbolicRef, strlen('refs/heads/'));
    $branchTipResult = run_git($root, ['rev-parse', '--verify', "refs/heads/{$branch}^{commit}"]);
    $targetTipResult = run_git($root, ['rev-parse', '--verify', "{$target}^{commit}"]);
    $branchTip = trim($branchTipResult['stdout']);
    $targetTip = trim($targetTipResult['stdout']);

    if (
        $branchTipResult['exit_code'] !== 0
        || $targetTipResult['exit_code'] !== 0
        || $branchTip === ''
        || ! hash_equals($branchTip, $targetTip)
    ) {
        return block_result(
            $branch,
            "merge head `{$target}` does not resolve exactly to local branch `{$branch}` tip",
        );
    }

    $worktree = worktree_for_branch($root, $branch);

    if ($worktree === null) {
        return block_result(
            $branch,
            "merge head `{$target}` names local branch `{$branch}`, but that branch has no linked worktree",
        );
    }

    $currentBranch = trim(run_git($root, ['branch', '--show-current'])['stdout']);

    if ($currentBranch !== 'main') {
        if ($branch !== 'main') {
            return block_result(
                $branch,
                'a non-main checkout may only merge local main into its current feature branch; '
                ."checkout `{$currentBranch}` cannot merge `{$branch}` through this boundary",
            );
        }

        return ok();
    }

    if (in_array($branch, ['main', 'master'], true)) {
        return block_result($branch, 'the main landing boundary requires one non-main feature branch');
    }

    $mergeBase = trim(run_git($root, ['merge-base', 'HEAD', $branch])['stdout']);

    if ($branchTip !== '' && $branchTip === $mergeBase) {
        return block_result(
            $branch,
            "feature branch `{$branch}` tip equals the merge base, so there are no commits to merge; "
            .'commit the feature worktree work first (or drop the merge if nothing should land)',
        );
    }

    $cleanlinessProblem = worktree_cleanliness_problem($worktree);

    if ($cleanlinessProblem !== null) {
        return block_result($branch, $cleanlinessProblem);
    }

    $check = final_distillation_check($root, $worktree, $branch, requireAnalyzer: true);
    if (! $check['ok']) {
        return $check;
    }

    $loop = (string) file_get_contents($worktree.'/.orbit/loop.md');

    if (! orbitLoopIsCompact($loop)) {
        $healthProblem = capture_health_problem_for_worktree($root, $worktree, $branch);
        if ($healthProblem !== null) {
            return block_result($branch, $healthProblem);
        }
    }

    $preview = run_git($root, ['merge-tree', '--write-tree', 'HEAD', 'refs/heads/'.$branch]);

    if ($preview['exit_code'] !== 0) {
        return block_result(
            $branch,
            'the non-mutating merge preview reported a conflict; return to BUILD, resolve it, then repeat proof and acceptance',
        );
    }

    return $check;
}

/**
 * @return array{ok: bool, subject: string, reason: string, warnings: list<string>}
 */
function check_worktree_remove(string $root, string $path): array
{
    $worktree = absolute_path($root, $path);

    if (! is_dir($worktree)) {
        return ok();
    }

    $branch = branch_for_worktree($root, $worktree) ?? $worktree;

    $cleanlinessProblem = worktree_cleanliness_problem($worktree);

    if ($cleanlinessProblem !== null) {
        return block_result($branch, $cleanlinessProblem);
    }

    $check = final_distillation_check($root, $worktree, $branch, allowLandedMain: true);

    if (! $check['ok']) {
        return $check;
    }

    $landedProblem = landed_feature_problem($root, $branch);

    if ($landedProblem !== null) {
        return block_result($branch, $landedProblem, $check['warnings']);
    }

    $archiveProblem = session_archive_problem($root, $branch);

    if ($archiveProblem !== null) {
        return block_result($branch, $archiveProblem, $check['warnings']);
    }

    $trackedProblem = session_archive_tracked_problem($root, $branch);

    if ($trackedProblem !== null) {
        return block_result($branch, $trackedProblem, $check['warnings']);
    }

    return $check;
}

/**
 * @return array{ok: bool, subject: string, reason: string, warnings: list<string>}
 */
function check_branch_delete(string $root, string $branch): array
{
    $branch = normalize_branch($branch);

    if ($branch === null || in_array($branch, ['main', 'master'], true)) {
        return ok();
    }

    if (! local_branch_exists($root, $branch)) {
        return ok();
    }

    $worktree = worktree_for_branch($root, $branch);

    if ($worktree === null) {
        $landedProblem = landed_feature_problem($root, $branch);

        if ($landedProblem !== null) {
            return block_result($branch, $landedProblem);
        }

        $archiveProblem = session_archive_problem($root, $branch);

        if ($archiveProblem !== null) {
            return block_result($branch, $archiveProblem);
        }

        $trackedProblem = session_archive_tracked_problem($root, $branch);

        return $trackedProblem === null ? ok() : block_result($branch, $trackedProblem);
    }

    $cleanlinessProblem = worktree_cleanliness_problem($worktree);

    if ($cleanlinessProblem !== null) {
        return block_result($branch, $cleanlinessProblem);
    }

    $check = final_distillation_check($root, $worktree, $branch, allowLandedMain: true);

    if (! $check['ok']) {
        return $check;
    }

    $landedProblem = landed_feature_problem($root, $branch);

    if ($landedProblem !== null) {
        return block_result($branch, $landedProblem, $check['warnings']);
    }

    $archiveProblem = session_archive_problem($root, $branch);

    if ($archiveProblem !== null) {
        return block_result($branch, $archiveProblem, $check['warnings']);
    }

    $trackedProblem = session_archive_tracked_problem($root, $branch);

    if ($trackedProblem !== null) {
        return block_result($branch, $trackedProblem, $check['warnings']);
    }

    return $check;
}

function worktree_cleanliness_problem(string $worktree): ?string
{
    $dirtyStatus = trim(run_git($worktree, ['status', '--porcelain', '--untracked-files=all'])['stdout']);

    if ($dirtyStatus === '') {
        return null;
    }

    $firstStatusLine = trim((string) strtok($dirtyStatus, "\n"));

    return (
        "feature worktree {$worktree} has uncommitted or untracked changes ({$firstStatusLine}); "
        .'commit, ignore, or remove them so the accepted HEAD exactly matches the verified state'
    );
}

function landed_feature_problem(string $root, string $branch): ?string
{
    $branch = normalize_branch($branch);

    if ($branch === null || ! local_branch_exists($root, $branch)) {
        return 'cleanup requires an exact local feature branch';
    }

    $result = run_git($root, ['merge-base', '--is-ancestor', 'refs/heads/'.$branch, 'refs/heads/main']);

    return $result['exit_code'] === 0
        ? null
        : "feature tip is not an ancestor of main; merge `{$branch}` before archive validation and cleanup";
}

/**
 * Worktree removal and branch deletion additionally require a session archive
 * in the primary checkout so cleanup never destroys the only loop evidence.
 */
function session_archive_problem(string $root, string $branch): ?string
{
    $sessionsDirectory = $root.'/.orbit/sessions';
    $slug = archive_slug($branch);
    $hint = 'run bin/orbit-session-archive from the feature worktree before cleanup';

    if (! is_dir($sessionsDirectory)) {
        return "no session archives exist at {$sessionsDirectory}; {$hint}";
    }

    $candidates = matching_session_archive_dirs($root, $branch);

    if ($candidates === []) {
        return "no session archive matching slug `{$slug}` was found under {$sessionsDirectory}; {$hint}";
    }

    foreach (array_reverse($candidates) as $candidate) {
        $loopPath = $candidate.'/loop.md';
        $agentSessionsDir = $candidate.'/agent-sessions';

        if (! is_file($loopPath) || is_link($loopPath)) {
            continue;
        }

        $receiptMode = archive_receipt_mode($candidate);

        if ($receiptMode !== null) {
            if ($receiptMode === 'compact' && compact_archive_receipt_is_valid($candidate, $root, $branch)) {
                return null;
            }

            if ($receiptMode !== 'full') {
                continue;
            }
        }

        if (agent_session_manifest_paths($agentSessionsDir) === []) {
            continue;
        }

        $health = capture_health_problem_for_loop($loopPath, $agentSessionsDir);
        if ($health !== null) {
            continue;
        }

        return null;
    }

    return "session archive for `{$slug}` under {$sessionsDirectory} needs loop.md plus a valid compact receipt or legacy/full agent session manifests; re-{$hint}";
}

/**
 * Cleanup also requires the matching archive directory and sessions index to be
 * tracked and committed on the primary checkout — presence alone is not enough.
 */
function session_archive_tracked_problem(string $root, string $branch): ?string
{
    $candidates = matching_session_archive_dirs($root, $branch);

    if ($candidates === []) {
        return 'no session archive matching slug `'.archive_slug($branch).'` is tracked for cleanup; commit the archive directory and .orbit/sessions/index.json on main first';
    }

    $archiveDir = null;

    foreach (array_reverse($candidates) as $candidate) {
        $receiptMode = archive_receipt_mode($candidate);

        if ($receiptMode === 'compact' && compact_archive_receipt_is_valid($candidate, $root, $branch)) {
            $archiveDir = $candidate;

            break;
        }

        if (
            is_file($candidate.'/loop.md')
            && ! is_link($candidate.'/loop.md')
            && agent_session_manifest_paths($candidate.'/agent-sessions') !== []
        ) {
            $archiveDir = $candidate;

            break;
        }
    }

    if ($archiveDir === null) {
        return 'matching session archive bytes are not ready to commit for cleanup; re-run bin/orbit-session-archive then commit the archive and index';
    }

    $relativeArchive = relative_repo_path($root, $archiveDir);

    if ($relativeArchive === null) {
        return "session archive {$archiveDir} is outside the primary checkout and cannot be committed for cleanup";
    }

    $trackedFiles = tracked_files_under($root, $relativeArchive);

    if ($trackedFiles === []) {
        return "session archive `{$relativeArchive}` exists on disk but is not tracked/committed; commit the exact archive directory plus .orbit/sessions/index.json before cleanup";
    }

    // Inspect the whole archive tree, not only the tracked subset, so untracked
    // required loop/evidence entries cannot slip past a tracked receipt.
    $archiveDirt = path_has_uncommitted_changes($root, $relativeArchive);

    if ($archiveDirt !== null) {
        return $archiveDirt;
    }

    $requiredEntries = session_archive_required_relative_entries($archiveDir, $relativeArchive);

    foreach ($requiredEntries as $requiredEntry) {
        $entryTracked = run_git($root, ['ls-files', '--error-unmatch', $requiredEntry]);

        if ($entryTracked['exit_code'] !== 0) {
            return "required archive entry `{$requiredEntry}` is present on disk but not tracked/committed; commit the exact archive directory before cleanup";
        }
    }

    $indexRelative = '.orbit/sessions/index.json';
    $indexPath = $root.'/'.$indexRelative;

    if (! is_file($indexPath) || is_link($indexPath)) {
        return 'cleanup requires tracked/committed `.orbit/sessions/index.json` matching the archive set; run `bin/orbit-session-index --write` and commit it';
    }

    $indexTracked = run_git($root, ['ls-files', '--error-unmatch', $indexRelative]);

    if ($indexTracked['exit_code'] !== 0) {
        return '`.orbit/sessions/index.json` exists but is not tracked/committed; commit it with the archive before cleanup';
    }

    $indexDirty = path_has_uncommitted_changes($root, $indexRelative);

    if ($indexDirty !== null) {
        return $indexDirty;
    }

    $archiveBasename = basename($archiveDir);
    $indexContents = (string) file_get_contents($indexPath);

    if (! str_contains($indexContents, $archiveBasename)) {
        return "`.orbit/sessions/index.json` does not reference archive `{$archiveBasename}`; regenerate with `bin/orbit-session-index --write` and commit before cleanup";
    }

    return null;
}

function relative_repo_path(string $root, string $absolutePath): ?string
{
    $rootReal = realpath($root) ?: $root;
    $pathReal = realpath($absolutePath) ?: $absolutePath;
    $prefix = rtrim($rootReal, '/').'/';

    if ($pathReal === $rootReal) {
        return '.';
    }

    if (! str_starts_with($pathReal, $prefix)) {
        return null;
    }

    return substr($pathReal, strlen($prefix));
}

/**
 * @return list<string>
 */
function tracked_files_under(string $root, string $relativeDirectory): array
{
    $result = run_git($root, ['ls-files', '-z', '--', $relativeDirectory]);

    if ($result['exit_code'] !== 0 || $result['stdout'] === '') {
        return [];
    }

    $files = array_values(array_filter(explode("\0", $result['stdout']), static fn (string $path): bool => $path !== ''));

    sort($files);

    return $files;
}

/**
 * Required archive paths that must be tracked for cleanup.
 * Compact receipts: receipt path (when present) plus copied_entries.
 * Legacy/full (no receipt): loop.md and agent-session manifests only.
 *
 * @return list<string>
 */
function session_archive_required_relative_entries(string $archiveDir, string $relativeArchive): array
{
    $receiptPath = $archiveDir.'/orbit-session-archive.json';
    $entries = [];

    if (is_file($receiptPath) && ! is_link($receiptPath)) {
        $entries[] = $relativeArchive.'/orbit-session-archive.json';
        $receipt = json_decode((string) file_get_contents($receiptPath), true);
        $copied = is_array($receipt) ? ($receipt['copied_entries'] ?? null) : null;

        if (is_array($copied)) {
            foreach ($copied as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $entries[] = $relativeArchive.'/'.$entry;
                }
            }
        }
    }

    if (is_file($archiveDir.'/loop.md') && ! is_link($archiveDir.'/loop.md')) {
        $entries[] = $relativeArchive.'/loop.md';
    }

    foreach (agent_session_manifest_paths($archiveDir.'/agent-sessions') as $manifestPath) {
        if (str_starts_with($manifestPath, $archiveDir.'/')) {
            $entries[] = $relativeArchive.'/'.substr($manifestPath, strlen($archiveDir) + 1);
        }
    }

    $entries = array_values(array_unique($entries));
    sort($entries);

    return $entries;
}

function path_has_uncommitted_changes(string $root, string $relativePath): ?string
{
    $status = run_git($root, ['status', '--porcelain', '--untracked-files=all', '--', $relativePath]);

    if ($status['exit_code'] !== 0) {
        return "unable to inspect commit state for `{$relativePath}` before cleanup";
    }

    $porcelain = trim($status['stdout']);

    if ($porcelain === '') {
        return null;
    }

    $first = trim((string) strtok($porcelain, "\n"));

    return "cleanup requires committed archive/index bytes; `{$relativePath}` still has uncommitted changes ({$first})";
}

/**
 * @return list<string>
 */
function matching_session_archive_dirs(string $root, string $branch): array
{
    $sessionsDirectory = $root.'/.orbit/sessions';
    $slug = archive_slug($branch);
    $candidates = [];

    if (! is_dir($sessionsDirectory)) {
        return [];
    }

    foreach (new DirectoryIterator($sessionsDirectory) as $entry) {
        if ($entry->isDot() || $entry->isLink() || ! $entry->isDir()) {
            continue;
        }

        $name = $entry->getFilename();

        if (preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}-[a-z0-9-]+$/', $name) !== 1) {
            continue;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}-\d{6}-'.preg_quote($slug, '/').'$/', $name) === 1) {
            $candidates[] = $entry->getPathname();

            continue;
        }

        // Versioned compact receipts may list compatible slugs / branch so a
        // content-identical archive created under a short feature slug still
        // satisfies branch-derived cleanup without a pre-success rename.
        if (session_archive_receipt_matches_branch_slug($entry->getPathname(), $branch, $slug)) {
            $candidates[] = $entry->getPathname();
        }
    }

    sort($candidates);

    return $candidates;
}

function session_archive_receipt_matches_branch_slug(string $archiveDir, string $branch, string $slug): bool
{
    $receiptPath = $archiveDir.'/orbit-session-archive.json';

    if (! is_file($receiptPath) || is_link($receiptPath)) {
        return false;
    }

    $receipt = json_decode((string) file_get_contents($receiptPath), true);

    if (! is_array($receipt)) {
        return false;
    }

    $schemaVersion = $receipt['schema_version'] ?? null;

    if (! in_array($schemaVersion, [2, 3], true) || ($receipt['archive_mode'] ?? null) !== 'compact') {
        return false;
    }

    $receiptBranch = $receipt['branch'] ?? null;

    if (is_string($receiptBranch) && $receiptBranch !== '') {
        if ($receiptBranch === $branch || archive_slug($receiptBranch) === $slug) {
            return true;
        }
    }

    $candidates = [];

    foreach (['slug', 'requested_slug'] as $key) {
        $value = $receipt[$key] ?? null;

        if (is_string($value) && $value !== '') {
            $candidates[] = archive_slug($value);
        }
    }

    $compatible = $receipt['compatible_slugs'] ?? null;

    if (is_array($compatible)) {
        foreach ($compatible as $value) {
            if (is_string($value) && $value !== '') {
                $candidates[] = archive_slug($value);
            }
        }
    }

    return in_array($slug, $candidates, true);
}

function archive_slug(string $value): string
{
    return orbit_land_archive_slug($value);
}

/**
 * @return array{ok: bool, subject: string, reason: string, warnings: list<string>}
 */
function final_distillation_check(
    string $root,
    string $worktree,
    string $subject,
    bool $requireAnalyzer = false,
    bool $allowLandedMain = false,
): array {
    $loopPath = rtrim($worktree, '/').'/.orbit/loop.md';

    if (! is_file($loopPath)) {
        return block_result($subject, "missing finalization state at {$loopPath}");
    }

    $contents = (string) file_get_contents($loopPath);

    if (orbitLoopIsCompact($contents)) {
        return compact_feature_check($root, $worktree, $subject, $contents, $allowLandedMain);
    }

    $section = markdown_section($contents, 'Final Distillation');

    if ($section === null) {
        return block_result($subject, "{$loopPath} has no `Final Distillation` section");
    }

    $placeholderLine = section_placeholder_line($section);

    if ($placeholderLine !== null) {
        return block_result(
            $subject,
            "{$loopPath} still contains template placeholders in `Final Distillation`: {$placeholderLine}",
        );
    }

    $loopOutcome = loop_outcome($section);

    if ($loopOutcome === null) {
        return block_result($subject, "{$loopPath} does not record a `Loop outcome` in `Final Distillation`");
    }

    if (! is_mergeable_loop_outcome($loopOutcome)) {
        return block_result(
            $subject,
            "{$loopPath} loop outcome is blocked or ambiguous: {$loopOutcome}; the value "
            .'must be exactly one of: complete | blocked | complete + loop improvement, '
            .'and only complete outcomes may pass a merge/cleanup boundary',
        );
    }

    $requirements = verification_requirements($root, $worktree, $subject);
    $requiredVerificationProblem = required_verification_problem($section, $worktree, $requirements);

    if ($requiredVerificationProblem !== null) {
        return block_result(
            $subject,
            "{$loopPath} required verification is incomplete: {$requiredVerificationProblem}",
        );
    }

    if ($requireAnalyzer) {
        $analyzerProblem = fresh_analyzer_problem($section);

        if ($analyzerProblem !== null) {
            return block_result($subject, "{$loopPath} {$analyzerProblem}");
        }
    }

    $warnings = [];
    $deferredAnalyzer = fresh_analyzer_deferred_value($section);

    if ($deferredAnalyzer !== null) {
        $warnings[] = "Fresh analyzer deferred: {$deferredAnalyzer}";
    }

    $signalProblem = signal_outcome_problem($section);

    if ($signalProblem !== null) {
        return block_result($subject, "{$loopPath} {$signalProblem}", $warnings);
    }

    $reconstructionWarning = reconstruction_smell_warning($contents, $section);

    if ($reconstructionWarning !== null) {
        $warnings[] = $reconstructionWarning;
    }

    return ok($warnings);
}

/**
 * @return array{ok: bool, subject: string, reason: string, warnings: list<string>}
 */
function compact_feature_check(
    string $root,
    string $worktree,
    string $subject,
    string $contents,
    bool $allowLandedMain = false,
): array {
    $problems = compact_loop_problems($contents);

    if ($problems !== []) {
        return block_result($subject, $worktree.'/.orbit/loop.md '.$problems[0]);
    }

    $branchTip = trim(run_git($worktree, ['rev-parse', 'HEAD'])['stdout']);
    $currentMainTip = trim(run_git($root, ['rev-parse', 'main'])['stdout']);
    $identityMainTip = $allowLandedMain
        ? (string) orbitLoopLabel($contents, 'Proof', 'Accepted main tip')
        : $currentMainTip;
    $identityProblem = orbitLoopAcceptedIdentityProblem($contents, $branchTip, $identityMainTip);

    if ($identityProblem === 'accepted feature tip does not equal the feature branch tip') {
        return block_result(
            $subject,
            'accepted feature tip does not equal the feature branch tip; return to PROVE and ACCEPT',
        );
    }

    if ($identityProblem === 'reviewed feature tip does not equal candidate HEAD') {
        return block_result(
            $subject,
            'reviewed feature tip does not equal the feature branch tip; return to PROVE and obtain reviewer PASS on exact HEAD',
        );
    }

    if ($identityProblem === 'main advanced after acceptance') {
        return block_result(
            $subject,
            'main advanced after acceptance; integrate main into the feature branch, then repeat PROVE and ACCEPT',
        );
    }

    if ($identityProblem !== null) {
        return block_result($subject, $identityProblem);
    }

    if (! $allowLandedMain) {
        $containsMain = run_git($worktree, ['merge-base', '--is-ancestor', $currentMainTip, $branchTip]);

        if ($containsMain['exit_code'] !== 0) {
            return block_result(
                $subject,
                'feature tip does not contain the accepted main tip; integrate main into the feature branch, then repeat PROVE and ACCEPT',
            );
        }
    }

    $compactDiffBase = $allowLandedMain
        ? (string) orbitLoopLabel($contents, 'Proof', 'Accepted main tip')
        : 'main';

    try {
        $compactChangedFiles = orbitLoopExactProofRoute($worktree, $compactDiffBase, 'HEAD')['changed_files'];
    } catch (Throwable $exception) {
        $message = $exception->getMessage();

        if (str_contains($message, 'orthogonal non-automated acceptance venue')) {
            return block_result($subject, $message);
        }

        return block_result(
            $subject,
            'unable to derive candidate changed-file inventory for acceptance venue resolution',
        );
    }

    $candidateVenue = candidate_acceptance_venue(
        $worktree,
        $branchTip,
        $compactDiffBase,
        $identityMainTip,
        $compactChangedFiles,
    );

    if ($candidateVenue['problem'] !== null) {
        return block_result($subject, $candidateVenue['problem']);
    }

    $blastRadiusProblem = orbitLoopBlastRadiusProblem($contents, $compactChangedFiles);

    if ($blastRadiusProblem !== null) {
        return block_result($subject, $blastRadiusProblem);
    }

    $acceptanceProblem = compact_acceptance_provenance_problem(
        $contents,
        $worktree,
        $branchTip,
        $compactChangedFiles,
        $candidateVenue['venue'],
    );

    if ($acceptanceProblem !== null) {
        return block_result($subject, $acceptanceProblem);
    }

    $proofProblem = compact_diff_proof_problem($root, $worktree, $subject, $compactChangedFiles);

    if ($proofProblem !== null) {
        return block_result($subject, $proofProblem);
    }

    return ok();
}


function normalize_branch(string $target): ?string
{
    $target = trim($target, " \t\n\r\0\x0B'\"");

    if ($target === '') {
        return null;
    }

    $target = preg_replace('/^refs\/heads\//', '', $target);
    $target = preg_replace('/^origin\//', '', (string) $target);

    if ($target === 'FETCH_HEAD' || preg_match('/^[0-9a-f]{7,40}$/i', $target) === 1) {
        return null;
    }

    return $target;
}

/**
 * @return array{ok: true, subject: string, reason: string, warnings: list<string>}
 */
function ok(array $warnings = []): array
{
    return ['ok' => true, 'subject' => '', 'reason' => '', 'warnings' => $warnings];
}

/**
 * @param  list<string>  $warnings
 * @return array{ok: false, subject: string, reason: string, warnings: list<string>}
 */
function block_result(string $subject, string $reason, array $warnings = []): array
{
    return ['ok' => false, 'subject' => $subject, 'reason' => $reason, 'warnings' => $warnings];
}

function block_message(string $subject, string $reason): string
{
    return <<<TEXT
        Orbit finalization gate blocked this merge/cleanup boundary.

        Subject: {$subject}
        Reason: {$reason}

        Before landing, complete the compact `.orbit/loop.md` Proof and Status: exact
        diff-routed verification, one independent review PASS, accepted venue and
        actor, exact accepted feature HEAD, and the actual current main tip. Commit,
        ignore, or remove every nonignored worktree file so the accepted HEAD is the
        tested surface. Resolve any merge-preview conflict and repeat affected proof
        and acceptance.

        Before cleanup, run `bin/orbit-session-archive`. A compact archive requires
        `loop.md` plus its schema-v2 or schema-v3 receipt; historical/full archives retain their
        manifest compatibility.

        Dry-run the packet shape first with:
        bin/orbit-feature-finalization-check --lint <worktree>/.orbit/loop.md

        Then rerun the same git command.

        TEXT;
}
