<?php

declare(strict_types=1);

/**
 * @param  list<string>  $changedFiles
 */
function compact_acceptance_provenance_problem(
    string $contents,
    string $worktree,
    string $featureTip,
    array $changedFiles,
    array $routedVenues,
): ?string {
    $review = orbitLoopLabel($contents, 'Proof', 'Review');
    $singular = orbitLoopStatusHead(orbitLoopLabel($contents, 'Proof', 'Acceptance venue')) ?? '';
    $plural = array_values(array_filter(array_map('trim', explode(',', (string) orbitLoopLabel($contents, 'Proof', 'Acceptance venues')))));
    $recordedVenues = $plural !== [] ? $plural : ($singular === '' ? [] : [$singular]);
    $actual = $recordedVenues;
    $expected = $routedVenues;
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    $known = ORBIT_LOOP_ACCEPTANCE_VENUES;
    if (count($recordedVenues) !== count(array_unique($recordedVenues))) {
        return 'acceptance venues must not contain duplicates';
    }
    foreach ($recordedVenues as $recordedVenue) {
        if (! in_array($recordedVenue, $known, true)) {
            return "acceptance venue {$recordedVenue} is unknown";
        }
    }
    if ($actual !== $expected) {
        return 'acceptance venues must exactly match diff-routed venues';
    }
    $acceptance = trim((string) orbitLoopLabel($contents, 'Proof', 'Acceptance'));
    $feedbackPath = $worktree.'/.orbit/feedback.jsonl';
    $events = [];

    if (file_exists($feedbackPath) || is_link($feedbackPath)) {
        try {
            $events = orbitFeedbackRead($feedbackPath);
            orbitFeedbackRequireActionableClosure($events);
        } catch (Throwable $exception) {
            return 'feedback stream is invalid: '.$exception->getMessage();
        }
    }

    if (count($recordedVenues) === 1 && ! orbitLoopVenueSatisfies($recordedVenues[0], $routedVenues[0] ?? 'automated')) {
        return "acceptance venue {$recordedVenues[0]} does not satisfy the diff-routed ".($routedVenues[0] ?? 'automated').' venue';
    }

    foreach ($recordedVenues as $recordedVenue) {
        $runtimeProblem = orbitLoopRuntimeProofProblem($contents, $recordedVenue, $featureTip, $worktree);
        if ($runtimeProblem !== null) {
            return $runtimeProblem;
        }
    }

    if (str_starts_with($acceptance, 'accepted - automated')) {
        if (in_array(
            $acceptance,
            [
                'accepted - automated - reviewer-confirmed no-human-judgment',
                'accepted - automated - reviewer-confirmed non-observable',
            ],
            true,
        )) {
            return orbitLoopReviewSaysNoHumanJudgment($review)
                ? null
                : 'automated acceptance says no human judgment, but review requires human judgment';
        }

        return 'acceptance provenance is invalid; automated acceptance must name reviewer-confirmed no-human-judgment';
    }

    if (preg_match('/^accepted - user @ (\S+)$/', $acceptance, $match) !== 1) {
        return 'acceptance provenance is invalid; expected an exact automated or user acceptance record';
    }

    if (! orbitLoopReviewRequiresHumanJudgment($review)) {
        return 'human acceptance is unnecessary because review records no human judgment';
    }

    $sourceRef = $match[1];

    if (! orbitFeedbackSourceRefIsValid($sourceRef)) {
        return 'user acceptance source reference is not a safe Codex or Claude reference';
    }

    $acceptanceEvents = array_values(array_filter(
        $events,
        static fn (array $event): bool => (
            ($event['type'] ?? null) === 'feedback.recorded'
            && ($event['context']['kind'] ?? null) === 'acceptance'
        ),
    ));
    $candidateEvents = array_values(array_filter(
        $acceptanceEvents,
        static fn (array $event): bool => ($event['candidate_commit'] ?? null) === $featureTip,
    ));

    if ($candidateEvents === []) {
        return 'user acceptance has no matching feedback event for the accepted candidate commit';
    }

    $sourceEvents = array_values(array_filter(
        $candidateEvents,
        static fn (array $event): bool => ($event['session_ref'] ?? null) === $sourceRef,
    ));

    if ($sourceEvents === []) {
        return 'user acceptance has no matching feedback event for the acceptance source reference';
    }

    $surface = 'acceptance.'.($recordedVenues[0] ?? '');

    foreach ($sourceEvents as $event) {
        if (($event['surface'] ?? null) === $surface) {
            return null;
        }
    }

    return "user acceptance has no matching feedback event for acceptance surface {$surface}";
}

/**
 * @param  list<string>  $changedFiles
 * @return array{venue: string, venues: list<string>, problem: ?string}
 */
function candidate_acceptance_venue(
    string $worktree,
    string $featureTip,
    string $baseRef,
    string $baseTip,
    array $changedFiles,
): array {
    $stateProblem = candidate_acceptance_venue_state_problem(
        $worktree,
        $featureTip,
        $baseRef,
        $baseTip,
        $changedFiles,
        false,
    );

    if ($stateProblem !== null) {
        return ['venue' => '', 'venues' => [], 'problem' => $stateProblem];
    }

    $contract = $worktree.'/bin/orbit-loop-contract.php';

    if (! is_file($contract) || is_link($contract)) {
        return [
            'venue' => '', 'venues' => [],
            'problem' => 'candidate acceptance venue contract must be a regular non-symlink file at bin/orbit-loop-contract.php',
        ];
    }

    $serializedChangedFiles = json_encode($changedFiles, JSON_THROW_ON_ERROR);
    $phpBinary = candidate_acceptance_venue_php_binary();

    if (! is_file($phpBinary) || ! is_executable($phpBinary)) {
        return [
            'venue' => '', 'venues' => [],
            'problem' => "candidate acceptance venue subprocess unable to start with PHP binary `{$phpBinary}`",
        ];
    }

    $script = <<<'PHP'
        $changedFiles = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        require $argv[1];
        $venues = orbitLoopAcceptanceVenues($changedFiles);
        fwrite(STDOUT, json_encode(['venue' => count($venues) === 1 ? $venues[0] : '', 'venues' => $venues], JSON_THROW_ON_ERROR).PHP_EOL);
        PHP;
    $result = run_process_with_timeout(
        [$phpBinary, '-r', $script, $contract],
        $worktree,
        candidate_acceptance_venue_timeout_ms(),
        $serializedChangedFiles,
    );

    if ($result['timed_out']) {
        return [
            'venue' => '', 'venues' => [],
            'problem' => 'candidate acceptance venue subprocess timed out',
        ];
    }

    if ($result['exit_code'] !== 0) {
        $stderr = trim($result['stderr']);
        $detail = $stderr === '' ? '' : ': '.strtok($stderr, "\r\n");

        return [
            'venue' => '', 'venues' => [],
            'problem' => "candidate acceptance venue subprocess exited with code {$result['exit_code']}{$detail}",
        ];
    }

    if ($result['stderr'] !== '') {
        return [
            'venue' => '', 'venues' => [],
            'problem' =>
                'candidate acceptance venue subprocess wrote unexpected stderr: '
                    .strtok(trim($result['stderr']), "\r\n"),
        ];
    }

    $decoded = json_decode(trim($result['stdout']), true);
    $venues = is_array($decoded['venues'] ?? null) ? array_values($decoded['venues']) : [];
    if ($venues === []) {
        $output = trim($result['stdout']);
        $knownVenues = ['automated', 'retained-incus', 'browser', 'host-macos'];
        $problem = match (true) {
            $output === '' => 'candidate acceptance venue subprocess returned empty output',
            str_contains($output, "\n"),
            str_contains($output, "\r"),
                => 'candidate acceptance venue subprocess returned unexpected extra or multiple output',
            preg_match('/^[a-z0-9-]+$/', $output) === 1 && ! in_array($output, $knownVenues, true)
                => "candidate acceptance venue subprocess returned unknown venue `{$output}`",
            default => 'candidate acceptance venue subprocess returned malformed output',
        };

        return [
            'venue' => '', 'venues' => [],
            'problem' => $problem,
        ];
    }

    $venue = is_string($decoded['venue'] ?? null) ? (string) $decoded['venue'] : '';

    $stateProblem = candidate_acceptance_venue_state_problem(
        $worktree,
        $featureTip,
        $baseRef,
        $baseTip,
        $changedFiles,
        true,
    );

    if ($stateProblem !== null) {
        return ['venue' => '', 'venues' => [], 'problem' => $stateProblem];
    }

    return ['venue' => $venue, 'venues' => $venues, 'problem' => null];
}

/**
 * @param  list<string>  $changedFiles
 */
function candidate_acceptance_venue_state_problem(
    string $worktree,
    string $featureTip,
    string $baseRef,
    string $baseTip,
    array $changedFiles,
    bool $afterResolution,
): ?string {
    $resolvedFeature = run_git($worktree, ['rev-parse', '--verify', 'HEAD^{commit}']);

    if ($resolvedFeature['exit_code'] !== 0 || ! hash_equals($featureTip, trim($resolvedFeature['stdout']))) {
        return $afterResolution
            ? 'candidate identity changed during acceptance venue resolution'
            : 'candidate identity does not match the accepted feature tip before acceptance venue resolution';
    }

    $resolvedBase = run_git($worktree, ['rev-parse', '--verify', "{$baseRef}^{commit}"]);

    if ($resolvedBase['exit_code'] !== 0 || ! hash_equals($baseTip, trim($resolvedBase['stdout']))) {
        return $afterResolution
            ? 'accepted base identity changed during acceptance venue resolution'
            : 'accepted base identity does not match before acceptance venue resolution';
    }

    try {
        $resolvedChangedFiles = orbitLoopExactProofRoute($worktree, $baseRef, 'HEAD')['changed_files'];
    } catch (Throwable) {
        return 'unable to validate candidate changed-file inventory for acceptance venue resolution';
    }

    if ($resolvedChangedFiles !== $changedFiles) {
        return $afterResolution
            ? 'candidate changed-file inventory changed during acceptance venue resolution'
            : 'candidate changed-file inventory does not match acceptance venue resolver input';
    }

    return null;
}

function candidate_acceptance_venue_php_binary(): string
{
    $override = getenv('ORBIT_FINALIZATION_PHP_BINARY');

    return is_string($override) && trim($override) !== '' ? trim($override) : PHP_BINARY;
}

function candidate_acceptance_venue_timeout_ms(): int
{
    $override = getenv('ORBIT_FINALIZATION_CANDIDATE_VENUE_TIMEOUT_MS');

    if (is_string($override) && ctype_digit($override)) {
        return max(10, min(10_000, (int) $override));
    }

    return 2_000;
}
