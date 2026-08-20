<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Closure;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Process\InputStream;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class SourceMountedCheckoutLifecycleLock
{
    private const int LOCK_WAIT_SECONDS = 30;

    /**
     * Bound for the local wait on the holder's ready marker: the remote flock
     * wait plus ssh connect and scheduling margin. A holder that dies without
     * ever printing the marker must surface as a failure, never as an
     * unbounded poll.
     */
    private const int ACQUIRE_WAIT_SECONDS = 45;

    private const int LEGACY_LOCK_STALE_SECONDS = 86_400;

    private const string LOCK_READY_MARKER = '__ORBIT_SOURCE_SYNC_LOCK_READY__';

    private const string LOCK_RELEASED_MARKER = '__ORBIT_SOURCE_SYNC_LOCK_RELEASED__';

    private SourceMountedCheckoutMutationFence $mutationFence;

    public function __construct(
        private string $host,
        private string $sourcePath,
    ) {
        $this->mutationFence = new SourceMountedCheckoutMutationFence(
            $sourcePath,
            bin2hex(random_bytes(16)),
        );
    }

    /**
     * @template TResult
     * @param  Closure(SourceMountedCheckoutMutationFence): TResult  $criticalSection
     * @return TResult
     */
    public function run(Closure $criticalSection): mixed
    {
        [$input, $holder] = $this->acquire();
        $result = null;
        $failure = null;

        try {
            $result = $criticalSection($this->mutationFence);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->release($input, $holder);
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }

        return $result;
    }

    /** @return array{InputStream, InvokedProcess} */
    private function acquire(): array
    {
        $input = new InputStream;
        $holder = Process::forever()
            ->input($input)
            ->start($this->holderProcessCommand());

        if (! $this->waitForReadyMarker($holder)) {
            $input->close();

            if ($holder->running()) {
                $holder->stop();
            }

            $result = $holder->wait();

            throw new RuntimeException(
                "Could not acquire source checkout lifecycle lock on {$this->host}:{$this->sourcePath}: "
                    .$this->processError($result),
            );
        }

        return [$input, $holder];
    }

    /**
     * True once the holder prints the ready marker; false when it dies or the
     * acquire deadline passes first. Never polls a dead holder indefinitely.
     */
    private function waitForReadyMarker(InvokedProcess $holder): bool
    {
        $deadline = microtime(true) + self::ACQUIRE_WAIT_SECONDS;

        while (true) {
            if (str_contains($holder->output(), self::LOCK_READY_MARKER)) {
                return true;
            }

            if (! $holder->running() || microtime(true) >= $deadline) {
                return false;
            }

            usleep(100_000);
        }
    }

    private function release(InputStream $input, InvokedProcess $holder): void
    {
        $input->close();
        $result = $holder->wait();

        if (! $result->successful() || ! str_contains($result->output(), self::LOCK_RELEASED_MARKER)) {
            throw new RuntimeException(
                "Could not release source checkout lifecycle lock on {$this->host}:{$this->sourcePath}: "
                    .$this->processError($result),
            );
        }
    }

    private function lockHolderCommand(): string
    {
        return implode("\n", [
            'target='.escapeshellarg($this->sourcePath),
            'lock_directory='.escapeshellarg(SourceMountedCheckoutMutationFence::LOCK_DIRECTORY),
            'lock='.escapeshellarg($this->lockPath()),
            'mutation_lock='.escapeshellarg($this->mutationFence->lockPath()),
            'generation_file='.escapeshellarg($this->mutationFence->generationFilePath()),
            'generation='.escapeshellarg($this->mutationFence->generation()),
            'generation_active=0',
            'legacy_lock="$target/.orbit-e2e-source-sync.lock"',
            'legacy_kind=""',
            'legacy_stale_after='.self::LEGACY_LOCK_STALE_SECONDS,
            'mkdir -p "$lock_directory" "$target"',
            'chmod 700 "$lock_directory"',
            'if ! command -v flock >/dev/null 2>&1; then echo "flock is required for source lifecycle locking." >&2; exit 127; fi',
            'exec 9>"$lock"',
            'if ! flock -w '
                .self::LOCK_WAIT_SECONDS
                .' 9; then echo "Timed out waiting '
                .self::LOCK_WAIT_SECONDS
                .'s for source sync lock $lock" >&2; exit 1; fi',
            'attempt=0',
            'while ! mkdir "$legacy_lock" 2>/dev/null; do',
            '  if [ -f "$legacy_lock" ]; then',
            '    exec 7<>"$legacy_lock"',
            '    if ! flock -w '
                .self::LOCK_WAIT_SECONDS
                .' 7; then echo "Timed out waiting for transitional source lock $legacy_lock" >&2; exit 1; fi',
            '    legacy_kind="file"',
            '    break',
            '  fi',
            '  now="$(date +%s)"',
            '  created_at="$(cat "$legacy_lock/created_at" 2>/dev/null || stat -c %Y "$legacy_lock" 2>/dev/null || printf 0)"',
            '  case "$created_at" in ""|*[!0-9]*) created_at=0;; esac',
            '  lock_age="$((now - created_at))"',
            '  owner_pid="$(cat "$legacy_lock/pid" 2>/dev/null || printf "")"',
            '  owner_host="$(cat "$legacy_lock/host" 2>/dev/null || printf "")"',
            '  current_host="$(hostname)"',
            '  case "$owner_pid" in ""|*[!0-9]*) owner_pid="";; esac',
            '  owner_live=0',
            '  if [ -n "$owner_pid" ] && [ "$owner_host" = "$current_host" ] && kill -0 "$owner_pid" 2>/dev/null; then owner_live=1; fi',
            '  if [ "$created_at" -gt 0 ] && [ "$lock_age" -gt "$legacy_stale_after" ] && [ "$owner_live" -eq 0 ]; then rm -rf "$legacy_lock"; continue; fi',
            '  attempt="$((attempt + 1))"',
            '  if [ "$attempt" -ge '
                .self::LOCK_WAIT_SECONDS
                .' ]; then echo "Timed out waiting '
                .self::LOCK_WAIT_SECONDS
                .'s for legacy source sync lock $legacy_lock" >&2; exit 1; fi',
            '  sleep 1',
            'done',
            'if [ -z "$legacy_kind" ]; then',
            '  legacy_kind="directory"',
            '  date +%s > "$legacy_lock/created_at"',
            '  printf \'%s\n\' "$$" > "$legacy_lock/pid"',
            '  hostname > "$legacy_lock/host"',
            'fi',
            'activate_generation() {',
            '  if ! (',
            '    if ! flock -w '.SourceMountedCheckoutMutationFence::WAIT_SECONDS.' -x 8; then exit 1; fi',
            '    generation_tmp="${generation_file}.$$"',
            '    printf \'%s\' "$generation" > "$generation_tmp"',
            '    mv -f "$generation_tmp" "$generation_file"',
            '  ) 8>"$mutation_lock"; then return 1; fi',
            '  generation_active=1',
            '}',
            'invalidate_generation() {',
            '  if [ "$generation_active" -eq 0 ]; then return 0; fi',
            '  if ! (',
            '    if ! flock -w '.SourceMountedCheckoutMutationFence::WAIT_SECONDS.' -x 8; then exit 1; fi',
            '    actual_generation="$(cat "$generation_file" 2>/dev/null || printf "")"',
            '    if [ "$actual_generation" = "$generation" ]; then rm -f "$generation_file"; fi',
            '  ) 8>"$mutation_lock"; then return 1; fi',
            '  generation_active=0',
            '}',
            'cleanup_lifecycle() {',
            '  if ! invalidate_generation; then return; fi',
            '  if [ "$legacy_kind" = "directory" ]; then rm -rf "$legacy_lock"; legacy_kind=""; rmdir "$target" 2>/dev/null || true; fi',
            '}',
            'trap cleanup_lifecycle EXIT',
            'if ! activate_generation; then echo "Could not activate source lifecycle generation." >&2; exit 1; fi',
            'printf \'%s\\n\' '.escapeshellarg(self::LOCK_READY_MARKER),
            'cat >/dev/null',
            'if ! invalidate_generation; then echo "Could not invalidate source lifecycle generation." >&2; exit 1; fi',
            'if [ "$legacy_kind" = "directory" ]; then rm -rf "$legacy_lock"; legacy_kind=""; rmdir "$target" 2>/dev/null || true; fi',
            'printf \'%s\\n\' '.escapeshellarg(self::LOCK_RELEASED_MARKER),
        ]);
    }

    private function lockPath(): string
    {
        $sourcePath = rtrim(string: $this->sourcePath, characters: '/');

        return (
            SourceMountedCheckoutMutationFence::LOCK_DIRECTORY
            .'/'
            .hash('sha256', $sourcePath !== '' ? $sourcePath : '/')
            .'.lock'
        );
    }

    private function holderProcessCommand(): string
    {
        $script = "set -euo pipefail\n{$this->lockHolderCommand()}";
        $remoteCommand = sprintf('bash -lc %s', escapeshellarg($script));
        $host = strtolower($this->host);

        if (
            in_array($host, ['', 'local', 'localhost', '127.0.0.1', '::1'], strict: true)
            || $host === strtolower((string) gethostname())
        ) {
            return $remoteCommand;
        }

        return sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
            escapeshellarg($this->host),
            escapeshellarg($remoteCommand),
        );
    }

    private function processError(ProcessResult $result): string
    {
        $error = trim($result->errorOutput());

        if ($error !== '') {
            return $error;
        }

        $output = trim($result->output());

        return $output !== '' ? $output : 'process exited with code '.($result->exitCode() ?? 'unknown');
    }
}
