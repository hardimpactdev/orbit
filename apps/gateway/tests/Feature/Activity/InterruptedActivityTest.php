<?php

declare(strict_types=1);

use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Models\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function interrupted_activity_row(string $status, int $ageSeconds, string $command = 'process:list'): int
{
    $createdAt = Carbon::now()->subSeconds($ageSeconds);

    return (int) DB::table('activity_log')->insertGetId([
        'log_name' => 'commands',
        'description' => $command,
        'event' => 'command',
        'request_id' => (string) Str::uuid(),
        'command' => $command,
        'status' => $status,
        'error_code' => $status === 'failed' ? 'http.500' : null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/** @return array{status: string, error_code: string|null, updated_at: string|null} */
function interrupted_activity_state(int $id): array
{
    $row = DB::table('activity_log')->where('id', $id)->first(['status', 'error_code', 'updated_at']);

    return ['status' => $row->status, 'error_code' => $row->error_code, 'updated_at' => (string) $row->updated_at];
}

describe('the interrupted Activity sweep', function (): void {
    it('waits longer than PHP-FPM lets any Gateway request run', function (): void {
        preg_match('/request_terminate_timeout = (\d+)s/', new GatewayFpmConfigRenderer()->renderPool('/checkout', '/home/orbit/.orbit'), $fpm);

        // PHP-FPM checks the limit on a heartbeat of a third of it, so a request can live four thirds of it.
        expect($fpm[1] ?? null)->not->toBeNull()
            ->and(config('orbit.activity_interrupted_after'))->toBeGreaterThan((int) ceil((int) $fpm[1] * 4 / 3) + 60);
    });

});

describe('the shutdown finalizer', function (): void {
    it('ends its running row when the request shuts down before recording an outcome', function (): void {
        $activity = Activity::query()->findOrFail(interrupted_activity_row('running', 1, 'instance:register'));

        ActivityShutdownFinalizer::arm($activity)->finalize();

        expect(interrupted_activity_state($activity->id))->toMatchArray(['status' => 'failed', 'error_code' => 'activity.interrupted']);
    });

    it('forgets every armed finalizer, so a request that never ended cannot touch a later row', function (): void {
        $abandoned = Activity::query()->findOrFail(interrupted_activity_row('running', 1));
        $finalizer = ActivityShutdownFinalizer::arm($abandoned);

        ActivityShutdownFinalizer::forgetArmed();
        $finalizer->finalize();
        ActivityShutdownFinalizer::finalizeArmed();

        expect(ActivityShutdownFinalizer::armedActivityIds())->toBe([])
            ->and($finalizer->armed())->toBeFalse()
            ->and(interrupted_activity_state($abandoned->id)['status'])->toBe('running');
    });

    it('does nothing once the request recorded its outcome', function (): void {
        $running = Activity::query()->findOrFail(interrupted_activity_row('running', 1));
        $disarmed = ActivityShutdownFinalizer::arm($running);
        $disarmed->disarm();
        $disarmed->finalize();

        $succeeded = Activity::query()->findOrFail(interrupted_activity_row('succeeded', 1));
        ActivityShutdownFinalizer::arm($succeeded)->finalize();

        expect(interrupted_activity_state($running->id)['status'])->toBe('running')
            ->and(interrupted_activity_state($succeeded->id))->toMatchArray(['status' => 'succeeded', 'error_code' => null]);
    });

    it('ends the row after a real fatal error and leaves a recorded outcome alone', function (string $mode, string $status, ?string $errorCode): void {
        $database = sys_get_temp_dir().'/orbit-gateway-test-'.bin2hex(random_bytes(8)).'.sqlite';
        touch($database);

        try {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Fixtures/Activity/ShutdownFatalProbe.php')],
                base_path(),
                ['ORBIT_TEST_DATABASE' => $database, 'ORBIT_ACTIVITY_PROBE_MODE' => $mode],
            );
            $process->setTimeout(120);
            $process->run();

            $row = new PDO('sqlite:'.$database)->query('SELECT status, error_code FROM activity_log')->fetch(PDO::FETCH_ASSOC);

            expect($process->isSuccessful())->toBeFalse()
                ->and($process->getOutput().$process->getErrorOutput())->toContain('Allowed memory size')
                ->and($row)->toBe(['status' => $status, 'error_code' => $errorCode]);
        } finally {
            @unlink($database);
        }
    })->with([
        'killed by the memory limit' => ['armed', 'failed', 'activity.interrupted'],
        'after the outcome was recorded' => ['disarmed', 'succeeded', null],
    ]);
});

describe('the command activity middleware', function (): void {
    beforeEach(function (): void {
        Route::middleware('api')->group(function (): void {
            Route::post('/api/v1/activity-probe', fn () => response()->json([
                'armed' => ActivityShutdownFinalizer::armedActivityIds(),
            ]))->name('activity:probe');
            Route::post('/api/v1/activity-probe/fail', function (): never {
                throw new RuntimeException('probe failure');
            })->name('activity:probe-fail');
            Route::post('/api/v1/activity-probe/stream', fn () => response()->stream(function (): void {
                echo json_encode(['armed' => ActivityShutdownFinalizer::armedActivityIds()], JSON_THROW_ON_ERROR);
            }))->name('activity:probe-stream');
        });
        Route::getRoutes()->refreshNameLookups();
    });

    it('arms the finalizer while a command runs and disarms it once the outcome is recorded', function (): void {
        $response = $this->postJson('/api/v1/activity-probe');
        $activity = Activity::query()->where('command', 'activity:probe')->sole();

        expect($response->json('armed'))->toBe([$activity->id])
            ->and($activity->status)->toBe('succeeded')
            ->and(ActivityShutdownFinalizer::armedActivityIds())->toBe([]);
    });

    it('disarms the finalizer after recording a failed command', function (): void {
        $this->withoutExceptionHandling();

        expect(fn () => $this->postJson('/api/v1/activity-probe/fail'))->toThrow(RuntimeException::class, 'probe failure')
            ->and(Activity::query()->where('command', 'activity:probe-fail')->sole()->status)->toBe('failed')
            ->and(ActivityShutdownFinalizer::armedActivityIds())->toBe([]);
    });

    it('keeps the finalizer armed until a streamed response ends', function (): void {
        $response = $this->postJson('/api/v1/activity-probe/stream');
        $activity = Activity::query()->where('command', 'activity:probe-stream')->sole();

        expect($activity->status)->toBe('running')
            ->and(ActivityShutdownFinalizer::armedActivityIds())->toBe([$activity->id]);

        $streamed = json_decode($response->streamedContent(), true, flags: JSON_THROW_ON_ERROR);

        expect($streamed['armed'])->toBe([$activity->id])
            ->and($activity->refresh()->status)->toBe('succeeded')
            ->and(ActivityShutdownFinalizer::armedActivityIds())->toBe([]);
    });

    it('does not arm the finalizer for a read, which has no running row', function (): void {
        Route::middleware('api')->get('/api/v1/activity-probe', fn () => response()->json([
            'armed' => ActivityShutdownFinalizer::armedActivityIds(),
        ]))->name('activity:probe-read');
        Route::getRoutes()->refreshNameLookups();

        expect($this->getJson('/api/v1/activity-probe')->json('armed'))->toBe([]);
    });
});
