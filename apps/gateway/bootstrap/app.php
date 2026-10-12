<?php

declare(strict_types=1);

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
use App\Domain\Tasks\TaskDefinitionInvalid;
use App\Domain\Tasks\TaskDefinitionViolation;
use App\Domain\Tasks\TaskSchedule;
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
use App\Infrastructure\Logging\GatewayExceptionStatus;
use App\Jobs\TaskVms\RefillTaskVmPool;
use App\Jobs\TaskVms\RefreshTaskVmBaseImages;
use App\Jobs\TaskVms\RefreshTaskVmWorkspaceTemplates;
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
use Illuminate\Support\Facades\DB;
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
        $schedule->command('annotations:dispatch')->everyTenSeconds()->withoutOverlapping();
        $schedule->command('orbit:deploy-development-defaults')->everyMinute()->withoutOverlapping(90);
        $schedule->command('orbit:desired-fleet-state')->everyFiveMinutes()->withoutOverlapping(10);
        $schedule->command('orbit:activity-finalize-interrupted')->everyFiveMinutes()->withoutOverlapping(10);
        $schedule->command('project-documents:probes:reconcile')->everyMinute()->withoutOverlapping(10);
        $schedule->command('project-documents:cleanup:work')->everyFiveMinutes()->withoutOverlapping(60);
        $schedule->command('orbit:vault-sync')->everyMinute()->withoutOverlapping(15);
        // The nightly task VM base image refresh, a job on the task VM queue below.
        $schedule->job(new RefreshTaskVmBaseImages)->dailyAt('03:00')
            ->when(static fn (): bool => config('task_vms.enabled') === true);
        // The nightly workspace templates of task VM Projects, built from the base image of the night.
        $schedule->job(new RefreshTaskVmWorkspaceTemplates)->dailyAt('04:00')
            ->when(static fn (): bool => config('task_vms.enabled') === true);
        // The task VM host sweep: orphaned VMs and clones, and live rows whose VM is gone.
        $schedule->job(new SweepTaskVmHosts)->everyTenMinutes()
            ->when(static fn (): bool => config('task_vms.enabled') === true);
        // The task VM pool: warm VMs up to each host's warm_vms, and a drain while task VMs are off. Inert while no
        // host has warm_vms and no pool VM exists.
        $schedule->job(new RefillTaskVmPool)->everyMinute()
            ->when(static fn (): bool => RefillTaskVmPool::due());
        // Task VM jobs (ADR 0200). In the foreground and last, so a release drain lets a running job finish.
        // One worker run takes a VM from launch to ready, so the next job never waits for the next start.
        // Every second, so a claim outside the task tick waits at most a second for its VM to start. Only a
        // job that a worker would take now starts one: a delayed retry or release waits for its time, as the
        // queue does, and a reserved job waits until its reservation expires.
        $schedule->command('queue:work task-vms --queue=task-vms --stop-when-empty --max-time=120 --timeout=1500')
            ->everySecond()->withoutOverlapping(30)
            ->when(static function (): bool {
                $now = now()->getTimestamp();

                return DB::table('jobs')->where('queue', 'task-vms')->where('available_at', '<=', $now)
                    ->where(static fn ($query) => $query->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<=', $now - (int) config('queue.connections.task-vms.retry_after')))
                    ->exists();
            });
    })
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->replace(ValidatePostSize::class, ValidateDocumentPostSize::class);
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

                return is_string($requestId) ? $requestId : '';
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
            $exceptions->render(function (TaskDefinitionInvalid $exception, Request $request) use ($requestIdFor): JsonResponse {
                $request->attributes->set('orbit.error_code', TaskDefinitionInvalid::CODE);
                $request->attributes->set('orbit.error_message', $exception->getMessage());

                return response()
                    ->json([
                        'error' => [
                            'code' => TaskDefinitionInvalid::CODE,
                            'message' => $exception->getMessage(),
                            'details' => [
                                'rules' => array_map(
                                    static fn (TaskDefinitionViolation $violation): array => $violation->toArray(),
                                    $exception->rules,
                                ),
                            ],
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
