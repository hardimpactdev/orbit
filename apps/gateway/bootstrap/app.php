<?php

declare(strict_types=1);

use App\Console\Kernel as GatewayConsoleKernel;
use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Tasks\TaskSchedule;
use App\Domain\TaskVms\TaskVmSettings;
use App\Http\Middleware\AnnounceDesiredCliVersion;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\GuardBrowserOrigins;
use App\Http\Middleware\HandleOperationIdempotency;
use App\Http\Middleware\NormalizeErrorDetails;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Middleware\ValidateRequestBodySize;
use App\Http\Responses\ApiErrorResponse;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Infrastructure\Swamp\SwampScheduledResults;
use App\Jobs\Fleet\AdmitFleetReboot;
use App\Jobs\Fleet\AdmitFleetRollout;
use App\Jobs\Fleet\CheckFleetRebootConfirmation;
use App\Jobs\Operations\PollSwampScheduledResults;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\TaskVms\RefreshTaskVmImages;
use App\Jobs\TaskVms\ReleaseIdleTaskVms;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Jobs\WebSocket\CloseWebSocketOperations;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (
    ini_get('zend.exception_ignore_args') !== '1'
    && (! function_exists('ini_set')
    || ini_set(option: 'zend.exception_ignore_args', value: '1') === false
    || ini_get('zend.exception_ignore_args') !== '1')
) {
    throw new RuntimeException('PHP must omit arguments from exception traces.');
}

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
        then: function (): void {
            require __DIR__.'/../routes/channels.php';
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        app(TaskSchedule::class)->register($schedule);
        // Work runs on the Gateway queue; the scheduler only dispatches (docs/reference/gateway-queue.md#scheduled-work).
        // Housekeeping of the Gateway's own tables and Horizon metrics: Gateway-local, no Node and no Swamp work.
        GatewaySchedule::command($schedule, 'orbit:activity-finalize-interrupted', uniqueFor: 600, overlapMinutes: 10)->everyFiveMinutes();
        GatewaySchedule::command($schedule, 'queue:prune-failed', uniqueFor: 3600, overlapMinutes: 60, arguments: ['--hours='.config()->integer('retention.failed_jobs_hours')])->daily();
        GatewaySchedule::command($schedule, 'activitylog:clean', uniqueFor: 3600, overlapMinutes: 60, arguments: ['--days='.config()->integer('retention.activity_log_days'), '--force'])->daily();
        // Horizon's metrics for its dashboard, only while Horizon runs.
        GatewaySchedule::command($schedule, 'horizon:snapshot', uniqueFor: 300)->everyFiveMinutes()
            ->when(static fn (): bool => GatewayQueueRedis::configured());
        // The one reconciler of Operations with their Swamp runs: it projects ended runs, expires budgets, finds lost starts,
        // forwards cancels and frees the target of a run that nothing proves ended.
        GatewaySchedule::job($schedule, new ReconcileSwampOperations)->everyMinute()
            ->when(static fn (): bool => GatewayQueueRedis::configured());
        // Reverb reports close WebSocket registry tasks; this fails the ones whose processes stopped reporting.
        GatewaySchedule::job($schedule, new CloseWebSocketOperations)->everyMinute()
            ->when(static fn (): bool => GatewayQueueRedis::configured());
        // Runs that finished in Swamp are read once a minute and projected; Swamp's commit feed needs a v3 datastore.
        GatewaySchedule::job($schedule, new PollSwampScheduledResults)->everyMinute()
            ->when(static fn (): bool => GatewayQueueRedis::configured() && app(SwampScheduledResults::class)->enabled());
        // Stage 6: every task VM host brings its image and seed up to date once a day. The release trigger is in the release projection.
        // A host with a refresh open returns it, so a repeat starts nothing.
        GatewaySchedule::job($schedule, new RefreshTaskVmImages)->dailyAt('03:20')
            ->when(static fn (): bool => TaskVmSettings::scheduled()->enabled);
        // Stage 8: a verified release admits the fleet rollout (release projection). Nodes outside the daytime set wait for the
        // 03:00–06:00 Amsterdam window, so the rollout is also admitted once when that window opens.
        GatewaySchedule::job($schedule, new AdmitFleetRollout)->dailyAt('03:05')->timezone('Europe/Amsterdam')
            ->when(static fn (): bool => GatewayQueueRedis::configured() && config()->boolean('fleet.rollout'));
        // ADR 0253: the nightly drained reboot pass, admitted after the rollout in the same window; it waits while the rollout runs.
        GatewaySchedule::job($schedule, new AdmitFleetReboot)->dailyAt('03:06')->timezone('Europe/Amsterdam')
            ->when(static fn (): bool => GatewayQueueRedis::configured() && config()->boolean('fleet.reboot'));
        GatewaySchedule::job($schedule, new CheckFleetRebootConfirmation)->everyFiveMinutes()
            ->when(static fn (): bool => GatewayQueueRedis::configured() && config()->boolean('fleet.reboot'));
        // Release stale Incus task starts through task_vm.slot_release.
        GatewaySchedule::job($schedule, new SweepTaskVmHosts)->everyThirtyMinutes()
            ->when(static fn (): bool => TaskVmSettings::scheduled()->enabled);
        // A group that runs no Operation for 30 minutes gives its task VM back to the pool.
        GatewaySchedule::job($schedule, new ReleaseIdleTaskVms)->everyFiveMinutes()
            ->when(static fn (): bool => TaskVmSettings::scheduled()->enabled);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(ValidatePostSize::class, ValidateRequestBodySize::class);
        // The Gateway keeps no session, so the Horizon dashboard has no CSRF token: only Sec-Fetch-Site: same-origin passes.
        $middleware->preventRequestForgery(originOnly: true);
        $executorInput = static fn (Request $request): bool => $request->is('api/v1/operation-executor/*');
        $middleware->trimStrings(except: [ValidateRequestBodySize::keepsExactBody(...), $executorInput]);
        $middleware->convertEmptyStringsToNull(except: [ValidateRequestBodySize::keepsExactBody(...), $executorInput]);
        $middleware->prepend(GuardBrowserOrigins::class);
        $middleware->prepend(EnsureRequestId::class);
        $middleware->api(prepend: [NormalizeErrorDetails::class, RecordCommandActivity::class], append: [AnnounceDesiredCliVersion::class, HandleOperationIdempotency::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireActiveWireGuardPeer::class);
        $middleware->appendToPriorityList(SubstituteBindings::class, RequireNodeAccess::class);
        $middleware->appendToPriorityList(RequireNodeAccess::class, RequireEnabledExtension::class);
        $middleware->appendToPriorityList(RequireEnabledExtension::class, HandleOperationIdempotency::class);
    })
    ->withExceptions(
        function (Exceptions $exceptions): void {
            // Runs before log context is built, which can exhaust memory again and stop later shutdown code.
            $exceptions->report(function (FatalError $exception): void {
                ActivityShutdownFinalizer::finalizeArmed();
            });
            // Laravel ignores HttpException before report callbacks; status filtering keeps 4xx refusals silent.
            $exceptions->stopIgnoring(HttpException::class);
            $exceptions->report(function (Throwable $exception): bool {
                return GatewayExceptionStatus::for($exception, request()->is('api/*')) >= 500;
            });

            // Domain exceptions render themselves (RendersApiError). These closures render framework exceptions and the API catch-all.
            $exceptions->render(function (ValidationException $exception, Request $request): JsonResponse {
                return ApiErrorResponse::make($request, 'validation.failed', 'The request data is invalid.', $exception->errors(), GatewayExceptionStatus::for($exception));
            });
            $exceptions->render(
                fn (ModelNotFoundException $exception, Request $request): JsonResponse => ApiErrorResponse::make($request, 'http.404', 'Resource not found.', [], 404),
            );
            $exceptions->render(
                fn (NotFoundHttpException $exception, Request $request): JsonResponse => ApiErrorResponse::make($request, 'http.404', 'Resource not found.', [], 404),
            );
            $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
                if (! $request->is('api/*')) {
                    return null;
                }

                $request->attributes->set('orbit.error_code', 'gateway.unhandled');

                return ApiErrorResponse::make(
                    $request,
                    'gateway.unhandled',
                    'The gateway could not complete the request.',
                    [],
                    GatewayExceptionStatus::for($exception, $request->is('api/*')),
                );
            });
            $exceptions->shouldRenderJsonWhen(
                fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
            );
        },
    )
    ->create();

// Providers register their own commands, so Artisan does not discover app/Console/Commands at each boot.
$app->singleton(ConsoleKernel::class, GatewayConsoleKernel::class);

return $app;
