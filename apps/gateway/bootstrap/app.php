<?php

declare(strict_types=1);

use App\Console\Scheduling\GatewaySchedule;
use App\Data\Instances\InstanceRemovalData;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskSchedule;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\Tools\ToolOperationException;
use App\Http\Middleware\AnnounceDesiredCliVersion;
use App\Http\Middleware\EnsureRequestId;
use App\Http\Middleware\GuardBrowserOrigins;
use App\Http\Middleware\NormalizeErrorDetails;
use App\Http\Middleware\RecordCommandActivity;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Middleware\RequireEnabledExtension;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Middleware\ValidateDocumentPostSize;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Caddy\Build\NodeCaddyBuildException;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Jobs\MaintainMachineRecipe;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\RefreshUpCloudTaskVmBaseImage;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use Illuminate\Console\Scheduling\Schedule;
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

return Application::configure(basePath: dirname(__DIR__))
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
        GatewaySchedule::command($schedule, 'annotations:dispatch', uniqueFor: 600, overlapMinutes: 1440)->everyTenSeconds();
        GatewaySchedule::command($schedule, 'orbit:deploy-development-defaults', uniqueFor: 1800, overlapMinutes: 90)->everyMinute();
        GatewaySchedule::command($schedule, 'orbit:desired-fleet-state', uniqueFor: 600, overlapMinutes: 10)->everyFiveMinutes();
        GatewaySchedule::command($schedule, 'orbit:activity-finalize-interrupted', uniqueFor: 600, overlapMinutes: 10)->everyFiveMinutes();
        GatewaySchedule::command($schedule, 'orbit:vault-sync', uniqueFor: 900, overlapMinutes: 15)->everyMinute();
        // Operations run only on the queue, so their sweep has nothing to do without it.
        GatewaySchedule::command($schedule, 'orbit:operations-sweep', uniqueFor: 300)->everyMinute()
            ->when(static fn (): bool => GatewayQueueRedis::configured());
        // Horizon's metrics for its dashboard, only while Horizon runs.
        GatewaySchedule::command($schedule, 'horizon:snapshot', uniqueFor: 300)->everyFiveMinutes()
            ->when(static fn (): bool => GatewayQueueRedis::configured());
        // Document cleanup stays in the scheduler: its permit belongs to the scheduler's start.
        $schedule->command('project-documents:probes:reconcile')->everyMinute()->withoutOverlapping(10);
        $schedule->command('project-documents:cleanup:work')->everyFiveMinutes()->withoutOverlapping(60);
        // Reconcile the deployed recipe each minute; only an identity change queues image builds.
        GatewaySchedule::job($schedule, new RefreshTaskVmBaseImages)->everyMinute()
            ->when(static fn (): bool => TaskVmSettings::scheduled()->enabled);
        GatewaySchedule::job($schedule, new MaintainMachineRecipe(snapshot: true))->dailyAt('03:00')->timezone('Europe/Amsterdam')
            ->when(static fn (): bool => TaskVmSettings::scheduled()->enabled);
        GatewaySchedule::job($schedule, new MaintainMachineRecipe)->everyMinute()
            ->when(static fn (): bool => TaskVmSettings::scheduled()->enabled);
        // The nightly UpCloud base image refresh, on the builds queue too. It runs while the UpCloud token file is set,
        // also with the overflow off.
        GatewaySchedule::job($schedule, new RefreshUpCloudTaskVmBaseImage)->dailyAt('02:00')
            ->when(static fn (): bool => TaskVmSettings::scheduled()->upcloudConfigured);
        // The task VM host sweep: orphaned VMs and clones, and live rows whose VM is gone. It also runs while only the
        // UpCloud token file is set, so no UpCloud server that Orbit created keeps costing money.
        GatewaySchedule::job($schedule, new SweepTaskVmHosts)->everyTenMinutes()
            ->when(static function (): bool {
                $taskVms = TaskVmSettings::scheduled();

                return $taskVms->enabled || $taskVms->upcloudConfigured;
            });
    })
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(ValidatePostSize::class, ValidateDocumentPostSize::class);
        // The Gateway keeps no session, so the Horizon dashboard has no CSRF token: only Sec-Fetch-Site: same-origin passes.
        $middleware->preventRequestForgery(originOnly: true);
        $middleware->trimStrings(except: [ValidateDocumentPostSize::preservesExactJson(...)]);
        $middleware->convertEmptyStringsToNull(except: [ValidateDocumentPostSize::preservesExactJson(...)]);
        $middleware->prepend(GuardBrowserOrigins::class);
        $middleware->prepend(EnsureRequestId::class);
        $middleware->api(prepend: [NormalizeErrorDetails::class, RecordCommandActivity::class, RequireEnabledExtension::class], append: [AnnounceDesiredCliVersion::class]);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequireActiveWireGuardPeer::class);
        $middleware->appendToPriorityList(SubstituteBindings::class, RequireNodeAccess::class);
        $middleware->appendToPriorityList(RequireNodeAccess::class, RequireEnabledExtension::class);
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

            /** The request ID that EnsureRequestId assigned, or the one the client sent when the middleware did not run. */
            $requestIdFor = static function (Request $request): string {
                $requestId = $request->attributes->get('orbit.request_id');

                if (! is_string($requestId) || $requestId === '') {
                    $requestId = $request->header('X-Orbit-Request-Id', '');
                }

                return $requestId;
            };

            $exceptions->render(function (InstanceRemovalException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $removal = InstanceRemovalData::fromModel($exception->removal)->toArray();
                $request->attributes->set('orbit.instance_removal', $removal);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['removal' => $removal],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });

            $notFound = static function (Request $request) use ($requestIdFor): JsonResponse {
                return response()
                    ->json([
                        'error' => [
                            'code' => 'http.404',
                            'message' => 'Resource not found.',
                            'details' => [],
                        ],
                    ], 404)
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            };

            $exceptions->render(function (ValidationException $exception, Request $request) use ($requestIdFor): JsonResponse {
                return response()
                    ->json([
                        'error' => [
                            'code' => 'validation.failed',
                            'message' => 'The request data is invalid.',
                            'details' => $exception->errors(),
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (NodeRoleValidationException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', 'validation.failed');

                return response()
                    ->json([
                        'error' => [
                            'code' => 'validation.failed',
                            'message' => $exception->getMessage(),
                            'details' => $exception->details,
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (NodeRoleOperationException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.error_message', $exception->getMessage());
                $request->attributes->set('orbit.command_result', $exception->result);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step, ...NodeRoleOperationException::detailsIn($exception), ...NodeCaddyBuildException::detailsIn($exception)],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (NodeProvisioningException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step, ...NodeRoleOperationException::detailsIn($exception), ...NodeCaddyBuildException::detailsIn($exception)],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (NodeRemovalException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step, ...NodeRoleOperationException::detailsIn($exception)],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (RuntimeConvergenceException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step, ...NodeCaddyBuildException::detailsIn($exception)],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (ProcessOperationException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (ScheduleOperationException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);

                $status = GatewayExceptionStatus::for($exception);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step],
                        ],
                    ], $status)
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (FirewallOperationException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.command_result', $exception->result);

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => ['step' => $exception->step],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (ToolOperationException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.tool_exception', $exception);

                $details = [
                    'step' => $exception->step,
                    'outcome' => $exception->outcome->value,
                ];

                if ($exception->toolId !== null) {
                    $details['id'] = $exception->toolId;
                }

                if ($exception->adoptionBlock !== null) {
                    $details['adoption_block'] = $exception->adoptionBlock;
                }

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => $details,
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (ResourceOperationException $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', $exception->errorCode);
                $request->attributes->set('orbit.error_message', $exception->getMessage());

                return response()
                    ->json([
                        'error' => [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => [...NodeCaddyBuildException::detailsIn($exception), ...$exception->details],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(function (RoleAssignmentException $exception, Request $request) use ($requestIdFor): JsonResponse {
                return response()
                    ->json([
                        'error' => [
                            'code' => 'node.role_conflict',
                            'message' => $exception->getMessage(),
                            'details' => [],
                        ],
                    ], GatewayExceptionStatus::for($exception))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->render(
                fn (ModelNotFoundException $exception, Request $request): JsonResponse => $notFound($request),
            );
            $exceptions->render(
                fn (NotFoundHttpException $exception, Request $request): JsonResponse => $notFound($request),
            );
            $exceptions->render(function (Throwable $exception, Request $request) use ($requestIdFor): ?JsonResponse {
                if (! $request->is('api/*')) {
                    return null;
                }

                $request->attributes->set('orbit.error_code', 'gateway.unhandled');

                return response()
                    ->json([
                        'error' => [
                            'code' => 'gateway.unhandled',
                            'message' => 'The gateway could not complete the request.',
                            'details' => [],
                        ],
                    ], GatewayExceptionStatus::for($exception, $request->is('api/*')))
                    ->header('X-Orbit-Request-Id', $requestIdFor($request));
            });
            $exceptions->shouldRenderJsonWhen(
                fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
            );
        },
    )
    ->create();
