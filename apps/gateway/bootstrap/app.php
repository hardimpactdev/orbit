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
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\SweepTaskVmHosts;
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
        GatewaySchedule::command($schedule, 'orbit:auto-deploy', uniqueFor: 600, overlapMinutes: 10)->everyMinute();
        // Reconcile the deployed recipe each minute; only an identity change queues image builds.
        GatewaySchedule::job($schedule, new RefreshTaskVmBaseImages)->everyMinute()
            ->when(static fn (): bool => TaskVmSettings::scheduled()->enabled);
        // The task VM host sweep: orphaned VMs and clones, and live rows whose VM is gone. It also runs while only the
        // UpCloud token file is set, so no UpCloud server that Orbit created keeps costing money.
        GatewaySchedule::job($schedule, new SweepTaskVmHosts)->everyTenMinutes()
            ->when(static function (): bool {
                $taskVms = TaskVmSettings::scheduled();

                return $taskVms->enabled || $taskVms->upcloudConfigured;
            });
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
