<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use DateTimeInterface;

/** @phpstan-type Stage array{status: string, started_at: ?string, finished_at: ?string, error: ?array{code: string, detail: string}} */
enum ConnLaunchStage: string
{
    case Workspace = 'workspace_server';
    case FirstMessage = 'first_message';
    case Publication = 'publication';
    case Application = 'application_preview';
    case Cleanup = 'cleanup';

    /** @return Stage */
    public function fact(string $status = 'pending', ?string $started = null, ?string $finished = null, ?string $code = null): array
    {
        return ['status' => $status, 'started_at' => $started, 'finished_at' => $finished,
            'error' => $status === 'failed' ? $this->error($code) : null];
    }

    /** @return Stage */
    public function recorded(mixed $fact): array
    {
        if (! is_array($fact) || ! is_string($status = $fact['status'] ?? null)
            || ! in_array($status, ['pending', 'running', 'succeeded', 'failed', 'cancelled', 'skipped'], true)) {
            return $this->fact();
        }
        $code = is_array($fact['error'] ?? null) ? ($fact['error']['code'] ?? null) : null;

        return $this->fact($status, self::recordedAt($fact['started_at'] ?? null), self::recordedAt($fact['finished_at'] ?? null), is_string($code) ? $code : null);
    }

    public static function at(?DateTimeInterface $time): ?string
    {
        return $time?->format(DateTimeInterface::ATOM);
    }

    private static function recordedAt(mixed $time): ?string
    {
        return is_string($time) && preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)\z/D', $time) === 1 ? $time : null;
    }

    /** @return array{code: string, detail: string} */
    private function error(?string $code): array
    {
        if ($code === 'operation.timed_out') {
            return ['code' => $code, 'detail' => 'The admitted work did not finish in time.'];
        }
        if ($this === self::Workspace && $code === 'instance.t3_unready') {
            return ['code' => $code, 'detail' => 'The Instance did not report a ready T3 server.'];
        }
        if ($this === self::Application) {
            $detail = match ($code) {
                'instance.container_process_inactive' => 'An application process did not become active.',
                'instance.complete_unavailable' => 'The Instance cannot currently be completed.',
                'operation.target_busy' => 'The Instance still has lifecycle work to settle.',
                default => null,
            };
            if ($detail !== null) {
                return ['code' => $code, 'detail' => $detail];
            }
        }

        if ($this === self::Cleanup) {
            $detail = match ($code) {
                'operation.target_busy', 'instance.lifecycle_busy' => 'The Instance still has lifecycle work to settle.',
                'instance.unsaved_work' => 'The Instance has work that teardown must preserve.',
                'conn.launch_wake_restore_unproven' => 'Orbit has not verified whether the woken Instance can safely sleep.',
                'swamp.start_uncertain' => 'Swamp has not confirmed whether the owned work ended.',
                default => null,
            };
            if ($detail !== null) {
                return ['code' => $code, 'detail' => $detail];
            }
        }

        return match ($this) {
            self::Workspace => ['code' => 'conn.launch_workspace_failed', 'detail' => 'Workspace server preparation did not complete.'],
            self::FirstMessage => ['code' => 'conn.launch_first_message_unconfirmed', 'detail' => 'The original T3 server has not confirmed acceptance of the first message.'],
            self::Publication => ['code' => 'conn.launch_publication_failed', 'detail' => 'The accepted thread has not been attached to the Conn profile.'],
            self::Application => ['code' => 'conn.launch_application_failed', 'detail' => 'Application completion did not succeed.'],
            self::Cleanup => ['code' => 'conn.launch_cleanup_pending', 'detail' => 'Launch cleanup is not verified. Orbit will resume cleanup.'],
        };
    }
}
