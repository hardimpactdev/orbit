<?php

declare(strict_types=1);

/**
 * Solo LAND helpers shared by finalization and bin/orbit-feature-land.
 *
 * Fail-closed request API (usable without the full finalization hook):
 *   solo_cli_lookup(), solo_list_processes()
 *
 * Finalization classification/checks still depend on hook primitives when used
 * from the hook: shell_words, worktrees, ok, block_result, landed_feature_problem,
 * session_archive_problem, session_archive_tracked_problem.
 *
 * JSON shape (live Solo CLI + test fake):
 *   success: {"ok":true,"data":{...}} or bare object
 *   missing: {"ok":false,"error":{"code":"not_found","message":"..."}}
 */

/**
 * @param  list<string>  $args
 * @return array{status: 'ok', data: array<string, mixed>}|array{status: 'not_found', reason: string}|array{status: 'error', reason: string}
 */
function solo_cli_lookup(string $soloCli, array $args, string $cwd, ?string $appDataDir = null): array
{
    if ($soloCli === '') {
        return ['status' => 'error', 'reason' => 'solo CLI path is empty'];
    }

    if (str_contains($soloCli, '/') && (! is_file($soloCli) || ! is_executable($soloCli))) {
        return ['status' => 'error', 'reason' => "solo CLI binary unavailable at {$soloCli}"];
    }

    $command = [$soloCli];

    if ($appDataDir !== null && $appDataDir !== '') {
        $command[] = '--app-data-dir';
        $command[] = $appDataDir;
    }

    $result = solo_run_process(array_merge($command, $args), $cwd);
    $stdout = trim($result['stdout']);
    $stderr = trim($result['stderr']);
    $decoded = null;

    if ($stdout !== '') {
        $decoded = json_decode($stdout, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $decoded = null;
        }
    }

    if ($result['exit_code'] !== 0) {
        if (is_array($decoded) && solo_is_not_found($decoded)) {
            return ['status' => 'not_found', 'reason' => solo_error_message($decoded)];
        }

        return [
            'status' => 'error',
            'reason' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'exit '.$result['exit_code']),
        ];
    }

    if (! is_array($decoded)) {
        return ['status' => 'error', 'reason' => 'solo CLI returned malformed or non-JSON output'];
    }

    if (array_key_exists('ok', $decoded) && $decoded['ok'] === false) {
        if (solo_is_not_found($decoded)) {
            return ['status' => 'not_found', 'reason' => solo_error_message($decoded)];
        }

        return ['status' => 'error', 'reason' => solo_error_message($decoded)];
    }

    $data = $decoded;

    if (isset($decoded['data']) && is_array($decoded['data'])) {
        $data = $decoded['data'];
    }

    return ['status' => 'ok', 'data' => $data];
}

/**
 * Live Solo process lists are paginated (offset/limit/hasMore/nextOffset).
 * Always page deterministically under the same app-data-dir context until
 * hasMore is false, or fail closed on invalid pagination shape.
 *
 * @return array{status: 'ok', processes: list<array{id: int, projectId: int, status: string}>}|array{status: 'error', reason: string}
 */
function solo_list_processes(string $soloCli, int $projectId, string $cwd, ?string $appDataDir = null): array
{
    $processes = [];
    $offset = 0;
    $limit = 50;
    $pages = 0;
    $maxPages = 100;

    while ($pages < $maxPages) {
        $lookup = solo_cli_lookup(
            $soloCli,
            [
                'processes',
                'list',
                '--project-id',
                (string) $projectId,
                '--offset',
                (string) $offset,
                '--limit',
                (string) $limit,
                '--json',
            ],
            $cwd,
            $appDataDir,
        );

        if ($lookup['status'] === 'not_found') {
            return ['status' => 'ok', 'processes' => []];
        }

        if ($lookup['status'] === 'error') {
            return ['status' => 'error', 'reason' => $lookup['reason']];
        }

        $data = $lookup['data'];
        $raw = $data['processes'] ?? null;

        if (! is_array($raw)) {
            return ['status' => 'error', 'reason' => 'solo processes list missing data.processes array'];
        }

        if (! array_key_exists('hasMore', $data) || ! is_bool($data['hasMore'])) {
            return ['status' => 'error', 'reason' => 'solo processes list missing boolean hasMore on page'];
        }

        foreach ($raw as $item) {
            if (! is_array($item)) {
                return ['status' => 'error', 'reason' => 'solo processes list contains a non-object entry'];
            }

            $id = $item['id'] ?? null;
            $owner = $item['projectId'] ?? null;
            $status = $item['status'] ?? null;

            if (! is_int($id) && ! (is_string($id) && preg_match('/^\d+$/', $id) === 1)) {
                return ['status' => 'error', 'reason' => 'solo process entry missing numeric id'];
            }

            if (! is_int($owner) && ! (is_string($owner) && preg_match('/^\d+$/', $owner) === 1)) {
                return ['status' => 'error', 'reason' => 'solo process entry missing numeric projectId'];
            }

            if ((int) $owner !== $projectId) {
                return [
                    'status' => 'error',
                    'reason' => 'solo processes list returned process '.(int) $id.' owned by project '.(int) $owner
                        .' while requesting project '.$projectId,
                ];
            }

            if (! is_string($status) || $status === '') {
                return ['status' => 'error', 'reason' => 'solo process entry missing status'];
            }

            $processes[] = [
                'id' => (int) $id,
                'projectId' => (int) $owner,
                'status' => strtolower($status),
            ];
        }

        if ($data['hasMore'] === false) {
            return ['status' => 'ok', 'processes' => $processes];
        }

        $nextOffset = $data['nextOffset'] ?? null;

        if (! is_int($nextOffset) && ! (is_string($nextOffset) && preg_match('/^\d+$/', $nextOffset) === 1)) {
            return ['status' => 'error', 'reason' => 'solo processes list hasMore without numeric nextOffset'];
        }

        $nextOffset = (int) $nextOffset;

        if ($nextOffset <= $offset) {
            return ['status' => 'error', 'reason' => 'solo processes list nextOffset is not monotonic'];
        }

        $offset = $nextOffset;
        $pages++;
    }

    return ['status' => 'error', 'reason' => 'solo processes list exceeded page budget while hasMore remained true'];
}

/**
 * @return array{status: 'ok', stoppable: list<int>, stopping: list<int>}|array{status: 'error', reason: string}
 */
function solo_process_phase_ids(string $soloCli, int $projectId, string $cwd, ?string $appDataDir = null): array
{
    $list = solo_list_processes($soloCli, $projectId, $cwd, $appDataDir);

    if ($list['status'] === 'error') {
        return $list;
    }

    $stoppable = [];
    $stopping = [];

    foreach ($list['processes'] as $process) {
        if (in_array($process['status'], ['running', 'starting'], true)) {
            $stoppable[] = $process['id'];
        }

        if ($process['status'] === 'stopping') {
            $stopping[] = $process['id'];
        }
    }

    sort($stoppable);
    sort($stopping);

    return ['status' => 'ok', 'stoppable' => $stoppable, 'stopping' => $stopping];
}

/**
 * @param  list<string>  $command
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function solo_run_process(array $command, string $cwd): array
{
    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd);

    if (! is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'unable to start process'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

/**
 * @param  array<string, mixed>  $decoded
 */
function solo_is_not_found(array $decoded): bool
{
    $code = null;

    if (isset($decoded['error']) && is_array($decoded['error']) && is_string($decoded['error']['code'] ?? null)) {
        $code = strtolower((string) $decoded['error']['code']);
    }

    return $code === 'not_found';
}

/**
 * @param  array<string, mixed>  $decoded
 */
function solo_error_message(array $decoded): string
{
    if (isset($decoded['error']) && is_array($decoded['error']) && is_string($decoded['error']['message'] ?? null)) {
        return (string) $decoded['error']['message'];
    }

    return 'solo CLI reported failure';
}

/**
 * Classify Solo LAND boundary actions by command position in each chain segment.
 * Quoted strings containing "solo" (e.g. rg 'solo') are not command positions.
 *
 * @return list<array{type: 'solo-process-stop', process_id: int, solo_cli: string, app_data_dir: ?string}|array{type: 'solo-project-delete', project_id: int, solo_cli: string, confirm_stop_running: bool, app_data_dir: ?string}|array{type: 'invalid', subject: string, reason: string}>
 */
function solo_boundary_actions(string $command): array
{
    $trimmed = trim($command);

    if ($trimmed === '') {
        return [];
    }

    $segments = preg_split('/\s*(?:&&|\|\||;|\n)\s*/', $trimmed) ?: [];
    $segments = array_values(array_filter($segments, static fn (string $segment): bool => trim($segment) !== ''));
    $actions = [];

    foreach ($segments as $segment) {
        $action = classify_solo_land_command(trim($segment));

        if ($action !== null) {
            $actions[] = $action;
        }
    }

    if ($actions === []) {
        return [];
    }

    // LAND Solo stop/delete must stand alone; chaining with any other segment is invalid.
    if (count($segments) > 1 || count($actions) > 1) {
        return [[
            'type' => 'invalid',
            'subject' => 'solo LAND command',
            'reason' => 'solo process-stop and project-delete boundaries must be a single unchained command',
        ]];
    }

    return $actions;
}

/**
 * Shared archive slug primitive used by finalization cleanup discovery and LAND.
 * Empty/punctuation-only values fall back to `session` (not `feature`).
 */
function orbit_land_archive_slug(string $value): string
{
    $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $value));
    $slug = trim($slug, '-');

    return $slug === '' ? 'session' : $slug;
}

/**
 * @return array{type: 'solo-process-stop', process_id: int, solo_cli: string, app_data_dir: ?string}|array{type: 'solo-project-delete', project_id: int, solo_cli: string, confirm_stop_running: bool, app_data_dir: ?string}|array{type: 'invalid', subject: string, reason: string}|null
 */
function classify_solo_land_command(string $command): ?array
{
    $words = shell_words($command);

    if ($words === [] || ! is_solo_cli_binary($words[0])) {
        return null;
    }

    $soloCli = $words[0];
    $confirmStopRunning = false;
    $appDataDir = null;
    $positional = [];

    for ($i = 1; $i < count($words); $i++) {
        $word = $words[$i];

        if ($word === '--json') {
            continue;
        }

        if ($word === '--app-data-dir') {
            if (! isset($words[$i + 1]) || str_starts_with($words[$i + 1], '-')) {
                return [
                    'type' => 'invalid',
                    'subject' => 'solo LAND command',
                    'reason' => 'solo --app-data-dir requires a directory argument',
                ];
            }

            $appDataDir = $words[$i + 1];
            $i++;

            continue;
        }

        if (str_starts_with($word, '--app-data-dir=')) {
            $value = substr($word, strlen('--app-data-dir='));

            if ($value === '') {
                return [
                    'type' => 'invalid',
                    'subject' => 'solo LAND command',
                    'reason' => 'solo --app-data-dir requires a directory argument',
                ];
            }

            // Reject equals+quoted / shell-fragment forms such as --app-data-dir='/path'
            // produced by concatenating --app-data-dir= with escapeshellarg(). Prefer
            // separate arguments: --app-data-dir <path>.
            if (
                str_contains($value, "'")
                || str_contains($value, '"')
                || str_contains($value, '\\')
                || str_contains($value, ' ')
            ) {
                return [
                    'type' => 'invalid',
                    'subject' => 'solo LAND command',
                    'reason' => 'solo --app-data-dir= rejects quoted or shell-fragment values; use separate --app-data-dir <path>',
                ];
            }

            $appDataDir = $value;

            continue;
        }

        if ($word === '--confirm-stop-running') {
            $confirmStopRunning = true;

            continue;
        }

        if (str_starts_with($word, '-')) {
            return [
                'type' => 'invalid',
                'subject' => 'solo LAND command',
                'reason' => "solo LAND command rejects unknown option `{$word}`",
            ];
        }

        $positional[] = $word;
    }

    if (
        count($positional) === 3
        && $positional[0] === 'processes'
        && $positional[1] === 'stop'
        && preg_match('/^\d+$/', $positional[2]) === 1
    ) {
        if ($confirmStopRunning) {
            return [
                'type' => 'invalid',
                'subject' => 'solo processes stop',
                'reason' => 'solo processes stop does not accept --confirm-stop-running',
            ];
        }

        return [
            'type' => 'solo-process-stop',
            'process_id' => (int) $positional[2],
            'solo_cli' => $soloCli,
            'app_data_dir' => $appDataDir,
        ];
    }

    if (
        count($positional) === 3
        && $positional[0] === 'projects'
        && $positional[1] === 'delete'
        && preg_match('/^\d+$/', $positional[2]) === 1
    ) {
        return [
            'type' => 'solo-project-delete',
            'project_id' => (int) $positional[2],
            'solo_cli' => $soloCli,
            'confirm_stop_running' => $confirmStopRunning,
            'app_data_dir' => $appDataDir,
        ];
    }

    // Malformed LAND shapes only: stop/delete present but not the exact form.
    if (
        count($positional) >= 2
        && $positional[0] === 'processes'
        && $positional[1] === 'stop'
    ) {
        return [
            'type' => 'invalid',
            'subject' => 'solo processes stop',
            'reason' => 'solo processes stop requires exactly one numeric process id and no extra operands',
        ];
    }

    if (
        count($positional) >= 2
        && $positional[0] === 'projects'
        && $positional[1] === 'delete'
    ) {
        return [
            'type' => 'invalid',
            'subject' => 'solo projects delete',
            'reason' => 'solo projects delete requires exactly one numeric project id and no extra operands',
        ];
    }

    // Read-only / non-LAND Solo subcommands (list, get, restart, ...) silent-pass.
    return null;
}

function is_solo_cli_binary(string $word): bool
{
    $base = basename($word);

    return $base === 'solo' || $base === 'solo-cli' || str_ends_with($base, '-solo-cli');
}

/**
 * @param  array{type: 'solo-process-stop', process_id: int, solo_cli: string, app_data_dir?: ?string}  $action
 * @return array{ok: bool, subject: string, reason: string, warnings: list<string>}
 */
function check_solo_process_stop(string $root, array $action): array
{
    $processId = $action['process_id'];
    $subject = "solo processes stop {$processId}";
    $appDataDir = $action['app_data_dir'] ?? null;
    $callerProcess = getenv('SOLO_PROCESS_ID');

    if (is_string($callerProcess) && preg_match('/^\d+$/', $callerProcess) === 1 && (int) $callerProcess === $processId) {
        return block_result($subject, "refuses self-stop of caller SOLO_PROCESS_ID={$processId}");
    }

    $processLookup = solo_cli_lookup(
        $action['solo_cli'],
        ['processes', 'get', (string) $processId, '--json'],
        $root,
        $appDataDir,
    );

    if ($processLookup['status'] === 'not_found') {
        return ok();
    }

    if ($processLookup['status'] === 'error') {
        return block_result($subject, 'solo process lookup failed: '.$processLookup['reason']);
    }

    $projectId = $processLookup['data']['projectId'] ?? null;

    if (! is_int($projectId) && ! (is_string($projectId) && preg_match('/^\d+$/', $projectId) === 1)) {
        return block_result($subject, "solo process {$processId} has no numeric projectId");
    }

    $projectId = (int) $projectId;
    $callerProject = getenv('SOLO_PROJECT_ID');

    if (is_string($callerProject) && preg_match('/^\d+$/', $callerProject) === 1 && (int) $callerProject === $projectId) {
        return block_result(
            $subject,
            "refuses self-project cleanup while SOLO_PROJECT_ID={$projectId} matches the target process owner",
        );
    }

    $projectLookup = solo_cli_lookup(
        $action['solo_cli'],
        ['projects', 'get', (string) $projectId, '--json'],
        $root,
        $appDataDir,
    );

    if ($projectLookup['status'] === 'not_found') {
        return block_result($subject, "solo process {$processId} references missing project {$projectId}");
    }

    if ($projectLookup['status'] === 'error') {
        return block_result($subject, 'solo project lookup failed: '.$projectLookup['reason']);
    }

    $owned = solo_resolve_owned_feature($root, $projectLookup['data'], $subject);

    if ($owned['ok'] === false) {
        return block_result($subject, $owned['reason']);
    }

    $cleanupGate = solo_landed_archive_gate($root, $owned['branch'], $subject);

    return $cleanupGate === null ? ok() : block_result($subject, $cleanupGate);
}

/**
 * @param  array{type: 'solo-project-delete', project_id: int, solo_cli: string, confirm_stop_running: bool, app_data_dir?: ?string}  $action
 * @return array{ok: bool, subject: string, reason: string, warnings: list<string>}
 */
function check_solo_project_delete(string $root, array $action): array
{
    $projectId = $action['project_id'];
    $subject = "solo projects delete {$projectId}";
    $appDataDir = $action['app_data_dir'] ?? null;

    if ($action['confirm_stop_running']) {
        return block_result(
            $subject,
            'solo projects delete must not use --confirm-stop-running; stop each session-owned running/starting process individually first',
        );
    }

    $callerProject = getenv('SOLO_PROJECT_ID');

    if (is_string($callerProject) && preg_match('/^\d+$/', $callerProject) === 1 && (int) $callerProject === $projectId) {
        return block_result(
            $subject,
            "refuses self-project cleanup while SOLO_PROJECT_ID={$projectId} matches the target project",
        );
    }

    $projectLookup = solo_cli_lookup(
        $action['solo_cli'],
        ['projects', 'get', (string) $projectId, '--json'],
        $root,
        $appDataDir,
    );

    if ($projectLookup['status'] === 'not_found') {
        return ok();
    }

    if ($projectLookup['status'] === 'error') {
        return block_result($subject, 'solo project lookup failed: '.$projectLookup['reason']);
    }

    $owned = solo_resolve_owned_feature($root, $projectLookup['data'], $subject);

    if ($owned['ok'] === false) {
        return block_result($subject, $owned['reason']);
    }

    $cleanupGate = solo_landed_archive_gate($root, $owned['branch'], $subject);

    if ($cleanupGate !== null) {
        return block_result($subject, $cleanupGate);
    }

    $phase = solo_process_phase_ids($action['solo_cli'], $projectId, $root, $appDataDir);

    if ($phase['status'] === 'error') {
        return block_result($subject, 'solo process list failed: '.$phase['reason']);
    }

    $nonTerminal = array_values(array_unique(array_merge($phase['stoppable'], $phase['stopping'])));

    if ($nonTerminal !== []) {
        return block_result(
            $subject,
            'solo project '.$projectId.' still has non-terminal processes ('.implode(', ', $nonTerminal).'); wait until running/starting/stopping processes are terminal before projects delete',
        );
    }

    return ok();
}

/**
 * @param  array<string, mixed>  $project
 * @return array{ok: true, branch: string, path: string}|array{ok: false, reason: string}
 */
function solo_resolve_owned_feature(string $root, array $project, string $subject): array
{
    $path = $project['path'] ?? null;

    if (! is_string($path) || $path === '') {
        return ['ok' => false, 'reason' => "{$subject} target project has no canonical path"];
    }

    $canonical = realpath($path) ?: $path;
    $rootReal = realpath($root) ?: $root;

    if ($canonical === $rootReal) {
        return ['ok' => false, 'reason' => "{$subject} refuses the primary/root project path `{$canonical}`"];
    }

    $cwd = realpath((string) getcwd()) ?: (string) getcwd();

    if ($cwd === $canonical || str_starts_with($cwd, rtrim($canonical, '/').'/')) {
        return [
            'ok' => false,
            'reason' => "{$subject} refuses self-cleanup while the caller cwd lives inside the target project path `{$canonical}`",
        ];
    }

    $branch = null;

    foreach (worktrees($root) as $worktree) {
        $worktreePath = realpath($worktree['path']) ?: $worktree['path'];

        if ($worktreePath === $canonical) {
            $branch = $worktree['branch'] ?? null;

            break;
        }
    }

    if ($branch === null || $branch === '') {
        return [
            'ok' => false,
            'reason' => "{$subject} requires exact canonical project path to equal a linked feature worktree of this checkout; got `{$canonical}`",
        ];
    }

    if (in_array($branch, ['main', 'master'], true)) {
        return [
            'ok' => false,
            'reason' => "{$subject} refuses primary branch worktree ownership for `{$branch}` at `{$canonical}`",
        ];
    }

    return ['ok' => true, 'branch' => $branch, 'path' => $canonical];
}

function solo_landed_archive_gate(string $root, string $branch, string $subject): ?string
{
    $landedProblem = landed_feature_problem($root, $branch);

    if ($landedProblem !== null) {
        return "{$subject} blocked: {$landedProblem}";
    }

    $archiveProblem = session_archive_problem($root, $branch);

    if ($archiveProblem !== null) {
        return "{$subject} blocked: {$archiveProblem}";
    }

    $trackedProblem = session_archive_tracked_problem($root, $branch);

    if ($trackedProblem !== null) {
        return "{$subject} blocked: {$trackedProblem}";
    }

    return null;
}
