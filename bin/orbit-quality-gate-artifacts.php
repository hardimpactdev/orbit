<?php

declare(strict_types=1);

function docs_only_artifact_problem(string $worktree): ?string
{
    $docsLintProblem = quality_gate_artifact_problem($worktree, 'docs-lint', '`composer docs-lint`');
    $qualityCheckProblem = quality_gate_artifact_problem($worktree, 'quality-check', '`composer quality-check`');

    if ($docsLintProblem === null || $qualityCheckProblem === null) {
        return null;
    }

    return $docsLintProblem.'; '.$qualityCheckProblem;
}

function quality_gate_artifact_problem(string $worktree, string $gate, string $label): ?string
{
    $artifact = latest_quality_gate_artifact($worktree, $gate);

    if ($artifact === null) {
        return "{$label} is required, but no latest successful artifact was found for {$gate}";
    }

    $exitCode = artifact_exit_code($artifact);

    if ($exitCode === null) {
        return "{$label} is required, but latest {$gate} artifact has no numeric exit_code";
    }

    if ($exitCode !== 0) {
        return "{$label} is required, but latest {$gate} artifact exited with code {$exitCode}";
    }

    $payload = $artifact['payload'] ?? null;

    if (! is_array($payload)) {
        return "{$label} is required, but latest {$gate} artifact payload is invalid";
    }

    $reservedProblem = reserved_quality_artifact_problem($gate, $payload);

    if ($reservedProblem !== null) {
        return "{$label} is required, but latest {$gate} artifact {$reservedProblem}";
    }

    $artifactCommit = is_array($payload) && is_array($payload['git'] ?? null)
        ? $payload['git']['commit'] ?? null
        : null;
    $artifactDirty = is_array($payload) && is_array($payload['git'] ?? null)
        ? $payload['git']['dirty'] ?? null
        : null;
    $candidateCommit = trim(run_git($worktree, ['rev-parse', 'HEAD'])['stdout']);

    if (! is_string($artifactCommit) || ! hash_equals($candidateCommit, $artifactCommit)) {
        return "{$label} is required, but latest {$gate} artifact does not belong to candidate HEAD {$candidateCommit}";
    }

    if ($artifactDirty !== false) {
        return "{$label} is required, but latest {$gate} artifact was not captured from a clean candidate";
    }

    return null;
}

/** @param array<string, mixed> $payload */
function reserved_quality_artifact_problem(string $gate, array $payload): ?string
{
    $expected = match ($gate) {
        'quality-check' => [
            'producer' => 'quality-check.sh',
            'command' => 'composer quality-check',
            'mode' => 'check',
        ],
        'docs-lint' => [
            'producer' => 'quality-gate-run',
            'command' => 'composer docs-lint',
            'mode' => 'check',
        ],
        default => null,
    };

    if ($expected === null) {
        return null;
    }

    foreach ($expected as $field => $value) {
        if (($payload[$field] ?? null) !== $value) {
            return "requires {$field}={$value}";
        }
    }

    $subgates = $payload['subgates'] ?? null;

    if (! is_array($subgates)) {
        return 'requires a subgates object';
    }

    if ($gate === 'docs-lint') {
        return $subgates === [] ? null : 'requires an exact empty subgate set';
    }

    $expectedLabels = QUALITY_CHECK_EXPECTED_SUBGATES;
    $actualLabels = array_keys($subgates);
    sort($expectedLabels);
    sort($actualLabels);

    if ($actualLabels !== $expectedLabels) {
        return 'requires the exact expected subgate set';
    }

    foreach ($subgates as $exitCode) {
        if (! is_int($exitCode) || $exitCode !== 0) {
            return 'all subgates must be integer exit code 0';
        }
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function latest_quality_gate_artifact(string $worktree, string $gate): ?array
{
    $directory = rtrim($worktree, '/').'/.orbit/quality-gates';

    if (! is_dir($directory)) {
        return null;
    }

    $paths = glob($directory.'/*.json');

    if ($paths === false) {
        return null;
    }

    $artifacts = [];

    foreach ($paths as $path) {
        $contents = file_get_contents($path);

        if ($contents === false) {
            continue;
        }

        $payload = json_decode($contents, true);

        if (! is_array($payload) || ($payload['gate'] ?? null) !== $gate) {
            continue;
        }

        $artifacts[] = [
            'path' => $path,
            'payload' => $payload,
            'timestamp' => artifact_timestamp($payload, $path),
        ];
    }

    if ($artifacts === []) {
        return null;
    }

    usort(
        $artifacts,
        fn (array $left, array $right): int => $right['timestamp'] <=> $left['timestamp'],
    );

    return $artifacts[0];
}

/**
 * @param  array<string, mixed>  $artifact
 */
function artifact_exit_code(array $artifact): ?int
{
    $payload = $artifact['payload'] ?? null;

    if (! is_array($payload)) {
        return null;
    }

    $exitCode = $payload['exit_code'] ?? null;

    if (is_int($exitCode)) {
        return $exitCode;
    }

    if (is_string($exitCode) && ctype_digit($exitCode)) {
        return (int) $exitCode;
    }

    return null;
}

/**
 * @param  array<string, mixed>  $payload
 */
function artifact_timestamp(array $payload, string $path): int
{
    $endedAt = $payload['ended_at'] ?? null;

    if (is_string($endedAt)) {
        $timestamp = strtotime($endedAt);

        if ($timestamp !== false) {
            return $timestamp;
        }
    }

    $modifiedAt = filemtime($path);

    return $modifiedAt === false ? 0 : $modifiedAt;
}

