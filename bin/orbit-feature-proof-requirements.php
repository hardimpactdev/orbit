<?php

declare(strict_types=1);

function compact_diff_proof_problem(
    string $root,
    string $worktree,
    string $subject,
    ?array $changedFiles = null,
): ?string {
    $loopPath = rtrim($worktree, '/').'/.orbit/loop.md';
    if (is_file($loopPath) && ! is_link($loopPath)) {
        $loop = (string) file_get_contents($loopPath);
        $featureTip = orbitLoopGitValue($worktree, ['rev-parse', 'HEAD']) ?? str_repeat('0', 40);
        $sliceProblems = orbitLoopSliceFinalizationProblems($loop, $worktree, $featureTip);
        if (
            in_array(
                orbitLoopStatusHead(orbitLoopLabel($loop, 'Status', 'State')),
                ['prove', 'accept', 'accepted', 'land'],
                true,
            )
            && $sliceProblems !== []
        ) {
            return 'Slices: '.$sliceProblems[0];
        }
    }
    $requirements = verification_requirements($root, $worktree, $subject);

    if ($changedFiles !== null) {
        $requirements['changed_files'] = $changedFiles;
        $requirements['docs_only'] = $changedFiles !== []
        && every(
            $changedFiles,
            fn (string $path): bool => is_docs_class_file($path),
        );
    }

    if ($requirements['changed_files'] === []) {
        return 'feature branch contains no changed files';
    }

    if ($requirements['docs_only']) {
        $problem = docs_only_artifact_problem($worktree);

        return $problem === null
            ? null
            : 'docs-only diff requires exact `composer docs-lint` or broader `composer quality-check` evidence; '
            .$problem;
    }

    $problem = quality_gate_artifact_problem($worktree, 'quality-check', '`composer quality-check`');

    return $problem === null
        ? null
        : 'non-docs diff requires exact `composer quality-check` evidence; '.$problem;
}

/**
 * @param  array{changed_files: list<string>, docs_only: bool, requires_quality_check: bool, has_php: bool, has_topology_relevant_php: bool, has_native_orbit_agent: bool}  $requirements
 */
function required_verification_problem(string $section, string $worktree, array $requirements): ?string
{
    $values = markdown_list_values($section, ['Required verification']);

    if ($values === []) {
        return 'missing `Required verification` rows';
    }

    foreach ([
        'Retained topology proof',
        '`composer quality-check`',
    ] as $requiredLabel) {
        if (! has_required_verification_row($values, $requiredLabel)) {
            return "missing {$requiredLabel} verification row";
        }
    }

    foreach ($values as $value) {
        if (is_blocked_verification_value($value)) {
            return $value;
        }
    }

    foreach ($values as $value) {
        if (is_placeholder_row_value($value)) {
            return "placeholder value in required verification row: {$value}";
        }
    }

    $docsOnlyProblem = docs_only_artifact_problem($worktree);

    if ($requirements['docs_only'] && $docsOnlyProblem !== null) {
        return (
            'docs-only diff requires `composer docs-lint` or broader `composer quality-check` evidence; '
            .$docsOnlyProblem
        );
    }

    $qualityCheck = required_verification_row($values, '`composer quality-check`');

    if ($requirements['requires_quality_check']) {
        $qualityCheckReason = $requirements['has_php'] ? 'PHP diff' : 'non-docs diff';

        if ($qualityCheck === null || ! is_passed_verification_value($qualityCheck)) {
            $current = $qualityCheck ?? 'missing row';

            return "{$qualityCheckReason} requires `composer quality-check: passed` with artifact-backed evidence; current row: {$current}";
        }

        $qualityCheckProblem = quality_gate_artifact_problem($worktree, 'quality-check', '`composer quality-check`');

        if ($qualityCheckProblem !== null) {
            return "{$qualityCheckReason} requires `composer quality-check` evidence; {$qualityCheckProblem}";
        }
    }

    $retainedTopologyProof = required_verification_row($values, 'Retained topology proof');

    if ($requirements['has_native_orbit_agent']) {
        if (! is_darwin_finalization_host()) {
            return (
                'native Orbit Agent diff requires macOS host topology proof from a Darwin implementation host; '
                .'rerun the finalization gate from the Mac that implemented the Tauri app change instead of substituting retained Incus topology'
            );
        }

        if (
            $retainedTopologyProof === null
            || ! is_passed_verification_value($retainedTopologyProof)
            || ! is_host_macos_topology_proof($retainedTopologyProof)
        ) {
            $current = $retainedTopologyProof ?? 'missing row';

            return (
                'native Orbit Agent diff requires Retained topology proof: passed with host-macos evidence '
                .'naming host=, os=, command=, and evidence=; current row: '
                .$current
            );
        }
    }

    if (
        $requirements['has_topology_relevant_php']
        && ($retainedTopologyProof === null
        || ! is_passed_verification_value($retainedTopologyProof))
        && ($retainedTopologyProof === null
        || ! is_release_acceptance_topology_deferral($retainedTopologyProof, $section))
    ) {
        $current = $retainedTopologyProof ?? 'missing row';

        return "topology-relevant PHP diff requires Retained topology proof: passed with retained topology evidence; current row: {$current}";
    }

    return null;
}

/**
 * @return array{changed_files: list<string>, docs_only: bool, requires_quality_check: bool, has_php: bool, has_topology_relevant_php: bool, has_native_orbit_agent: bool}
 */
function verification_requirements(string $root, string $worktree, string $subject): array
{
    $changedFiles = changed_files($root, $worktree, $subject);
    $docsOnly = $changedFiles !== [] && every($changedFiles, fn (string $path): bool => is_docs_class_file($path));
    $phpFiles = array_values(array_filter($changedFiles, fn (string $path): bool => is_php_file($path)));
    $topologyRelevantPhpFiles = array_values(array_filter($phpFiles, fn (string $path): bool => is_topology_relevant_php_file(
        $path,
    )));
    $nativeOrbitAgentFiles = array_values(array_filter($changedFiles, fn (string $path): bool => is_native_orbit_agent_file(
        $path,
    )));

    return [
        'changed_files' => $changedFiles,
        'docs_only' => $docsOnly,
        'requires_quality_check' => $changedFiles !== [] && ! $docsOnly,
        'has_php' => $phpFiles !== [],
        'has_topology_relevant_php' => $topologyRelevantPhpFiles !== [],
        'has_native_orbit_agent' => $nativeOrbitAgentFiles !== [],
    ];
}

/**
 * @return list<string>
 */
function changed_files(string $root, string $worktree, string $subject): array
{
    $branch = normalize_branch($subject);

    if ($branch !== null && local_branch_exists($root, $branch)) {
        return branch_changed_files($root, $branch);
    }

    if (! is_dir($worktree)) {
        return [];
    }

    return branch_changed_files($worktree, 'HEAD');
}

/**
 * @return list<string>
 */
function branch_changed_files(string $cwd, string $headRef, string $baseRef = 'main'): array
{
    return orbitLoopChangedFiles($cwd, $headRef, $baseRef);
}

/**
 * @param  list<string>  $items
 */
function every(array $items, callable $callback): bool
{
    foreach ($items as $item) {
        if (! $callback($item)) {
            return false;
        }
    }

    return true;
}

function is_markdown_file(string $path): bool
{
    return preg_match('/\.md$/i', $path) === 1;
}

/**
 * Docs-class diffs are satisfied by docs-lint evidence: Markdown anywhere plus
 * non-PHP harness surfaces (.agents/, docs/, harness-signals/, LOOP.md.example).
 */
function is_docs_class_file(string $path): bool
{
    if (is_markdown_file($path)) {
        return true;
    }

    if (is_php_file($path)) {
        return false;
    }

    return (
        str_starts_with($path, '.agents/')
        || str_starts_with($path, 'docs/')
        || str_starts_with($path, 'harness-signals/')
        || $path === 'LOOP.md.example'
    );
}

function is_php_file(string $path): bool
{
    return preg_match('/\.php$/i', $path) === 1;
}

function is_topology_relevant_php_file(string $path): bool
{
    if (! is_php_file($path)) {
        return false;
    }

    if (str_contains('/'.$path, '/tests/')) {
        return false;
    }

    return ! str_starts_with($path, 'apps/docs/') && ! str_starts_with($path, 'bin/');
}

function is_native_orbit_agent_file(string $path): bool
{
    return str_starts_with($path, 'apps/macos/') && ! is_markdown_file($path);
}

function is_darwin_finalization_host(): bool
{
    return strcasecmp(finalization_host_os_family(), 'Darwin') === 0;
}

function finalization_host_os_family(): string
{
    $override = getenv('ORBIT_FINALIZATION_HOST_OS_FAMILY');

    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }

    return PHP_OS_FAMILY;
}

function is_host_macos_topology_proof(string $value): bool
{
    $normalized = strtolower($value);

    foreach (['host-macos', 'host=', 'os=', 'command', 'evidence'] as $requiredToken) {
        if (! str_contains($normalized, $requiredToken)) {
            return false;
        }
    }

    return true;
}
