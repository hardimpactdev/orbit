<?php

declare(strict_types=1);

/**
 * @param  array{
 *     root?: string,
 *     loop?: ?string,
 *     subject?: string,
 *     changed_files?: list<string>|null,
 *     venue?: string,
 *     venues?: list<string>
 * }  $options
 * @return array{
 *     ok: bool,
 *     problem: ?string,
 *     candidate: string,
 *     dirty: bool,
 *     docs_only: bool,
 *     gate: string,
 *     artifact: ?string,
 *     venue: string,
 *     venues: list<string>,
 *     runtime: string
 * }
 */
function orbit_proof_receipt(string $worktree, array $options = []): array
{
    $root = $options['root'] ?? $worktree;
    $subject = $options['subject'] ?? 'HEAD';
    $changedFiles = $options['changed_files'] ?? null;
    $loopPath = array_key_exists('loop', $options)
        ? $options['loop']
        : (is_file($worktree.'/.orbit/loop.md') && ! is_link($worktree.'/.orbit/loop.md')
            ? $worktree.'/.orbit/loop.md'
            : null);

    $candidateResult = run_git($worktree, ['rev-parse', 'HEAD']);
    $candidate = trim($candidateResult['stdout']);
    $dirtyStatus = trim(run_git($worktree, ['status', '--porcelain', '--untracked-files=all'])['stdout']);
    $dirty = $dirtyStatus !== '';
    $resolvedChangedFiles = $changedFiles;
    $assertedVenues = null;
    $docsOnly = false;
    $overrideVenue = is_string($options['venue'] ?? null) && $options['venue'] !== ''
        ? (string) $options['venue']
        : null;
    $venue = $overrideVenue ?? 'automated';
    $venues = ['automated'];

    if ($candidate !== '' && $candidateResult['exit_code'] === 0 && ! $dirty) {
        try {
            if ($resolvedChangedFiles !== null) {
                $venues = orbitLoopAcceptanceVenues($resolvedChangedFiles);
            } else {
                $route = orbitLoopExactProofRoute($worktree);
                $venues = $route['venues'];
                $resolvedChangedFiles = $route['changed_files'];
            }
        } catch (Throwable) {
            // Fall back to compact_diff_proof_problem routing below.
        }
    }

    if ($resolvedChangedFiles !== null && $assertedVenues === null) {
        $venues = orbitLoopAcceptanceVenues($resolvedChangedFiles);
    }

    if (isset($options['venues'])) {
        $assertedVenues = array_values($options['venues']);
        $knownVenues = defined('ORBIT_LOOP_ACCEPTANCE_VENUES')
            ? ORBIT_LOOP_ACCEPTANCE_VENUES
            : ['automated', 'retained-incus', 'browser', 'host-macos'];
        $validAssertion = every($assertedVenues, static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '' && in_array($candidate, $knownVenues, true))
            && count($assertedVenues) === count(array_unique($assertedVenues));

        if (! $validAssertion) {
            return [
                'ok' => false,
                'problem' => 'proof receipt venues assertion is empty, unknown, or duplicated',
                'candidate' => $candidate,
                'dirty' => $dirty,
                'docs_only' => false,
                'gate' => 'quality-check',
                'artifact' => null,
                'venue' => '',
                'venues' => $venues,
                'runtime' => 'not applicable',
            ];
        }

        $venues = $assertedVenues;
    }

    if ($overrideVenue !== null && count($venues) === 1 && ! orbitLoopVenueSatisfies($overrideVenue, $venues[0])) {
        return [
            'ok' => false,
            'problem' => 'proof receipt venue assertion does not match the diff-derived acceptance route',
            'candidate' => $candidate,
            'dirty' => $dirty,
            'docs_only' => false,
            'gate' => 'quality-check',
            'artifact' => null,
            'venue' => '',
            'venues' => $venues,
            'runtime' => 'not applicable',
        ];
    }

    if (count($venues) > 1 && $overrideVenue !== null) {
        $receipt = ['ok' => false, 'problem' => 'mixed-venue proof receipts cannot accept a singular venue override', 'candidate' => $candidate, 'dirty' => $dirty, 'docs_only' => false, 'gate' => 'quality-check', 'artifact' => null, 'venue' => '', 'venues' => $venues, 'runtime' => 'not applicable'];
        return $receipt;
    }

    if ($overrideVenue === null && count($venues) === 1) {
        $venue = $venues[0];
    }

    if (is_array($resolvedChangedFiles)) {
        $docsOnly = $resolvedChangedFiles !== []
            && every($resolvedChangedFiles, static fn (string $path): bool => is_docs_class_file($path));
    }

    $gate = $docsOnly ? 'docs-lint' : 'quality-check';
    $artifactPath = $dirty ? null : orbit_proof_receipt_artifact_path($worktree, $docsOnly);

    if ($artifactPath !== null && $docsOnly && str_contains($artifactPath, '/quality-check-')) {
        $gate = 'quality-check';
    }

    $runtime = 'not applicable';
    $receipt = [
        'ok' => false,
        'problem' => null,
        'candidate' => $candidate,
        'dirty' => $dirty,
        'docs_only' => $docsOnly,
        'gate' => $gate,
        'artifact' => $artifactPath,
        'venue' => $venue,
        'venues' => $venues,
        'runtime' => $runtime,
    ];

    if ($candidate === '' || $candidateResult['exit_code'] !== 0) {
        $receipt['problem'] = 'unable to resolve candidate HEAD';

        return $receipt;
    }

    if ($dirty) {
        $first = trim((string) strtok($dirtyStatus, "\n"));
        $receipt['problem'] = (
            'candidate worktree is dirty ('.$first.'); commit, ignore, or remove uncommitted files so the receipt matches a clean HEAD'
        );

        return $receipt;
    }

    $proofProblem = compact_diff_proof_problem($root, $worktree, $subject, $resolvedChangedFiles);

    if ($proofProblem !== null) {
        $receipt['problem'] = $proofProblem;
        $receipt['artifact'] = null;

        return $receipt;
    }

    $loopContents = is_string($loopPath) && is_file($loopPath) && ! is_link($loopPath)
        ? (string) file_get_contents($loopPath)
        : null;

    if (array_diff($venues, ['automated']) !== [] && ! is_string($loopContents)) {
        $receipt['problem'] = 'non-automated venue requires a readable `.orbit/loop.md` runtime receipt';

        return $receipt;
    }

    if (is_string($loopContents) && array_diff($venues, ['automated']) !== []) {
        if (count($venues) > 1) {
            foreach ($venues as $requiredVenue) {
                $runtimeProblem = orbitLoopRuntimeProofProblem($loopContents, $requiredVenue, $candidate, $worktree);
                if ($runtimeProblem !== null) {
                    $receipt['problem'] = $runtimeProblem;
                    return $receipt;
                }
            }
            $receipt['runtime'] = 'passed - venues='.implode(',', $venues);
        } else {
            $runtimeProblem = orbitLoopRuntimeProofProblem($loopContents, $venue, $candidate, $worktree);
            if ($runtimeProblem !== null) {
                $receipt['problem'] = $runtimeProblem;
                return $receipt;
            }
            $runtimeValue = orbitLoopNestedLabel($loopContents, 'Proof', 'Verification', 'runtime');
            $receipt['runtime'] = is_string($runtimeValue) && $runtimeValue !== '' ? $runtimeValue : 'passed';
        }
    }

    $receipt['ok'] = true;

    return $receipt;
}

/**
 * @param  array{
 *     root?: string,
 *     loop?: ?string,
 *     subject?: string,
 *     changed_files?: list<string>|null,
 *     venue?: string
 * }  $options
 */
function orbit_proof_receipt_problem(string $worktree, array $options = []): ?string
{
    return orbit_proof_receipt($worktree, $options)['problem'];
}

function orbit_proof_receipt_artifact_path(string $worktree, bool $docsOnly): ?string
{
    if ($docsOnly) {
        $docsLint = latest_quality_gate_artifact($worktree, 'docs-lint');
        $qualityCheck = latest_quality_gate_artifact($worktree, 'quality-check');
        $docsLintProblem = quality_gate_artifact_problem($worktree, 'docs-lint', '`composer docs-lint`');
        $qualityCheckProblem = quality_gate_artifact_problem($worktree, 'quality-check', '`composer quality-check`');

        if ($docsLintProblem === null && is_array($docsLint) && is_string($docsLint['path'] ?? null)) {
            return (string) $docsLint['path'];
        }

        if ($qualityCheckProblem === null && is_array($qualityCheck) && is_string($qualityCheck['path'] ?? null)) {
            return (string) $qualityCheck['path'];
        }

        return null;
    }

    $qualityCheck = latest_quality_gate_artifact($worktree, 'quality-check');

    if (is_array($qualityCheck) && is_string($qualityCheck['path'] ?? null)) {
        return (string) $qualityCheck['path'];
    }

    return null;
}
