<?php

declare(strict_types=1);

namespace App\E2E\Support;

use RuntimeException;

/**
 * Run independent per-role shell tasks concurrently on an Incus host inside a
 * single SSH invocation.
 *
 * Each task runs as a background job whose output is captured to a per-task
 * log. The generated script reports `__orbit_task_status <label> <code>` and
 * `__orbit_task_timing <label> <milliseconds>` markers so callers keep
 * per-role phase timings while collapsing N serial host round trips into one.
 */
final readonly class IncusParallelHostTasks
{
    /**
     * @param  array<string, string>  $tasksByLabel  label => bash snippet executed on the Incus host
     */
    public static function run(
        IncusHost $host,
        array $tasksByLabel,
        E2EPhaseTimer $timer,
        string $timerPrefix,
        int $timeoutSeconds = 600,
        string $failureMessage = 'Parallel Incus host tasks failed',
    ): void {
        if ($tasksByLabel === []) {
            return;
        }

        $result = $host->run(self::script($tasksByLabel), $timeoutSeconds);
        $output = $result->output()."\n".$result->errorOutput();

        self::recordTimings($output, array_keys($tasksByLabel), $timer, $timerPrefix);

        if ($result->successful()) {
            return;
        }

        $failedLabels = self::failedLabels($output, array_keys($tasksByLabel));
        $details = trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output());

        throw new RuntimeException(sprintf(
            '%s [%s]: %s',
            $failureMessage,
            implode(', ', $failedLabels),
            $details,
        ));
    }

    /**
     * @param  array<string, string>  $tasksByLabel
     */
    public static function script(array $tasksByLabel): string
    {
        $startLines = [];
        $waitLines = [];

        foreach (array_keys($tasksByLabel) as $index => $label) {
            $pid = 'PID_TASK_'.($index + 1);
            $task = $tasksByLabel[$label];
            $logPath = '"$dir"/'.escapeshellarg("{$label}.log");

            $startLines[] = sprintf(
                '( TASK_START_MS="$(now_ms)"; ( %s ); TASK_CODE=$?; TASK_END_MS="$(now_ms)"; echo "__orbit_task_timing %s $((TASK_END_MS - TASK_START_MS))"; exit "$TASK_CODE" ) > %s 2>&1 & %s=$!',
                $task,
                $label,
                $logPath,
                $pid,
            );
            $waitLines[] = sprintf(
                'wait "$%1$s" && echo "__orbit_task_status %2$s 0" || { TASK_CODE=$?; echo "__orbit_task_status %2$s $TASK_CODE"; echo "task %2$s failed" >&2; cat %3$s >&2 || true; if [ "$STATUS" -eq 0 ]; then STATUS=$TASK_CODE; fi; }',
                $pid,
                $label,
                $logPath,
            );
            $waitLines[] = sprintf('grep -h "__orbit_task_timing " %s || true', $logPath);
        }

        return implode("\n", [
            'dir="$(mktemp -d /tmp/orbit-e2e-parallel-XXXXXX)"',
            "trap 'rm -rf \"\$dir\"' EXIT",
            'now_ms() { if command -v python3 >/dev/null 2>&1; then python3 -c '
                .escapeshellarg('import time; print(int(time.time() * 1000))')
                .'; else echo "$(($(date +%s) * 1000))"; fi; }',
            ...$startLines,
            'STATUS=0',
            ...$waitLines,
            // A trailing test instead of a top-level `exit` keeps login-shell
            // logout hooks (e.g. Ubuntu clear_console) from clobbering the
            // script's exit status under `bash -lc` + `set -e`.
            '[ "$STATUS" -eq 0 ]',
        ]);
    }

    /**
     * @param  list<string>  $labels
     */
    private static function recordTimings(
        string $output,
        array $labels,
        E2EPhaseTimer $timer,
        string $timerPrefix,
    ): void {
        $allowed = array_fill_keys($labels, true);

        if (preg_match_all('/__orbit_task_timing\s+(\S+)\s+(\d+)/', $output, $matches, PREG_SET_ORDER) === false) {
            return;
        }

        foreach ($matches as $match) {
            if (! isset($allowed[$match[1]])) {
                continue;
            }

            $timer->recordExternal("{$timerPrefix}.{$match[1]}", (int) $match[2] / 1000);
        }
    }

    /**
     * @param  list<string>  $labels
     * @return list<string>
     */
    private static function failedLabels(string $output, array $labels): array
    {
        $statuses = array_fill_keys($labels, null);

        if (preg_match_all('/__orbit_task_status\s+(\S+)\s+(\d+)/', $output, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                if (array_key_exists($match[1], $statuses)) {
                    $statuses[$match[1]] = (int) $match[2];
                }
            }
        }

        $failed = array_keys(array_filter(
            $statuses,
            fn (?int $status): bool => $status !== 0 && $status !== null,
        ));

        if ($failed !== []) {
            return $failed;
        }

        // A failure without any per-task status markers means the wrapper
        // itself broke before tasks could report; attribute it to every task.
        return array_keys(array_filter($statuses, fn (?int $status): bool => $status !== 0));
    }
}
