<?php

declare(strict_types=1);

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Instances\InstanceRemovalStatus;
use App\Domain\Instances\Removal\InstanceRemovalException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRemovalException;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Infrastructure\Processes\CommandResult;
use App\Models\InstanceRemoval;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The request attributes that a domain exception renderer sets for the activity and log middleware.
 *
 * @return array<string, mixed>
 */
function domain_exception_attributes(Request $request): array
{
    $attributes = array_intersect_key($request->attributes->all(), array_flip([
        'orbit.error_code',
        'orbit.error_message',
        'orbit.command_result',
        'orbit.tool_exception',
        'orbit.instance_removal',
    ]));
    ksort($attributes);

    return $attributes;
}

describe('domain exception rendering', function (): void {
    it('renders the error envelope, the request ID, and the request attributes', function (Throwable $exception, int $status, array $error, Closure $attributes): void {
        $request = Request::create('/api/test/domain-exception', 'GET');
        $request->attributes->set('orbit.request_id', 'assigned-id');

        $response = app(ExceptionHandler::class)->render($request, $exception);

        $expectedAttributes = $attributes($exception);
        ksort($expectedAttributes);

        expect($response->getStatusCode())->toBe($status)
            ->and($response->getContent())->toBe(json_encode(['error' => $error], JSON_THROW_ON_ERROR))
            ->and($response->headers->get('Content-Type'))->toBe('application/json')
            ->and($response->headers->get('X-Orbit-Request-Id'))->toBe('assigned-id')
            ->and(domain_exception_attributes($request))->toBe($expectedAttributes);

        // Through the API middleware, NormalizeErrorDetails sends empty details as a JSON object.
        Route::middleware('api')->get('/api/test/domain-exception', static fn (): never => throw $exception);
        $requestId = (string) Str::uuid();
        $error['details'] = $error['details'] === [] ? new stdClass : $error['details'];

        $response = $this->getJson('/api/test/domain-exception', ['X-Orbit-Request-Id' => $requestId]);

        expect($response->getStatusCode())->toBe($status)
            ->and($response->getContent())->toBe(json_encode(['error' => $error], JSON_THROW_ON_ERROR))
            ->and($response->headers->get('Content-Type'))->toBe('application/json')
            ->and($response->headers->get('X-Orbit-Request-Id'))->toBe($requestId);
    })->with('domain exception renderers');
});

dataset('domain exception renderers', function (): array {
    $result = new CommandResult(1, 'out', 'err', 12, false);

    return [
        'instance removal' => [
            fn (): Throwable => new InstanceRemovalException('instance.remove_failed', 503, new InstanceRemoval([
                'id' => 'render-removal',
                'requested_instance_id' => 7,
                'requested_name' => 'render-app',
                'force' => true,
                'inventory_digest' => 'digest',
                'total' => 0,
                'status' => InstanceRemovalStatus::Failed,
            ]), new RuntimeException('wrapped', previous: new RuntimeConvergenceException('finalize-source', 'instance.source_finalize_failed', 'cross-device: refused', result: $result))),
            503,
            ['code' => 'instance.remove_failed', 'message' => 'Instance removal was accepted but remains incomplete.', 'details' => ['removal' => $removal = [
                'operation_id' => 'render-removal',
                'id' => 7,
                'name' => 'render-app',
                'force' => true,
                'status' => 'failed',
                'current_step' => null,
                'total' => 0,
                'completed' => 0,
                'remaining' => 0,
                'failed_step' => null,
                'error_code' => null,
            ]]],
            static fn (InstanceRemovalException $exception): array => [
                'orbit.error_code' => 'instance.remove_failed',
                'orbit.command_result' => $result,
                'orbit.instance_removal' => $removal,
            ],
        ],
        'node role validation' => [
            fn (): Throwable => new NodeRoleValidationException('The role is invalid.', ['role' => ['The role is unknown.']]),
            422,
            ['code' => 'validation.failed', 'message' => 'The role is invalid.', 'details' => ['role' => ['The role is unknown.']]],
            static fn (): array => ['orbit.error_code' => 'validation.failed'],
        ],
        'node role operation' => [
            fn (): Throwable => new NodeRoleOperationException('converge:metrics', 'node_role.convergence_failed', 'metrics.image_pull_failed', 'The role did not converge.', $result),
            502,
            ['code' => 'node_role.convergence_failed', 'message' => 'The role did not converge.', 'details' => ['step' => 'converge:metrics', 'error_code' => 'metrics.image_pull_failed']],
            static fn (NodeRoleOperationException $exception): array => [
                'orbit.error_code' => 'node_role.convergence_failed',
                'orbit.error_message' => 'The role did not converge.',
                'orbit.command_result' => $exception->result,
            ],
        ],
        'node provisioning' => [
            fn (): Throwable => new NodeProvisioningException('roles', 'node.provision_failed', 'Provisioning failed.', new NodeRoleOperationException('converge:vpn', 'node_role.convergence_failed', 'node_role.node_busy', 'busy'), $result),
            502,
            ['code' => 'node.provision_failed', 'message' => 'Provisioning failed.', 'details' => ['step' => 'roles', 'error_code' => 'node_role.node_busy']],
            static fn (): array => ['orbit.error_code' => 'node.provision_failed', 'orbit.command_result' => $result],
        ],
        'node removal' => [
            fn (): Throwable => new NodeRemovalException('wireguard', 'node.remove_failed', 'Removal failed.', $result),
            502,
            ['code' => 'node.remove_failed', 'message' => 'Removal failed.', 'details' => ['step' => 'wireguard']],
            static fn (): array => ['orbit.error_code' => 'node.remove_failed', 'orbit.command_result' => $result],
        ],
        'runtime convergence' => [
            fn (): Throwable => new RuntimeConvergenceException('caddy-config', 'app-dev.caddy_config_failed', 'Caddy did not load.', result: $result),
            502,
            ['code' => 'app-dev.caddy_config_failed', 'message' => 'Caddy did not load.', 'details' => ['step' => 'caddy-config']],
            static fn (): array => ['orbit.error_code' => 'app-dev.caddy_config_failed', 'orbit.command_result' => $result],
        ],
        'process operation' => [
            fn (): Throwable => new ProcessOperationException('start', 'process.start_failed', 'The process did not start.'),
            502,
            ['code' => 'process.start_failed', 'message' => 'The process did not start.', 'details' => ['step' => 'start']],
            static fn (): array => ['orbit.error_code' => 'process.start_failed', 'orbit.command_result' => null],
        ],
        'schedule operation' => [
            fn (): Throwable => new ScheduleOperationException('install', ScheduleErrorCode::RetryConflict, 'Another retry is running.'),
            409,
            ['code' => 'schedule.retry_conflict', 'message' => 'Another retry is running.', 'details' => ['step' => 'install']],
            static fn (): array => ['orbit.error_code' => 'schedule.retry_conflict'],
        ],
        'firewall operation' => [
            fn (): Throwable => new FirewallOperationException('apply', 'firewall.apply_failed', 'UFW refused the rule.', $result, status: 503),
            503,
            ['code' => 'firewall.apply_failed', 'message' => 'UFW refused the rule.', 'details' => ['step' => 'apply']],
            static fn (): array => ['orbit.error_code' => 'firewall.apply_failed', 'orbit.command_result' => $result],
        ],
        'tool operation' => [
            fn (): Throwable => new ToolOperationException('adopt', 'tool.adoption_unsupported', ToolOutcome::ManagerFailed, 409, 1, 'apt', 'nginx', null, 'The package is protected.', toolId: 4, adoptionBlock: 'protected'),
            409,
            ['code' => 'tool.adoption_unsupported', 'message' => 'The package is protected.', 'details' => ['step' => 'adopt', 'outcome' => 'manager_failed', 'id' => 4, 'adoption_block' => 'protected']],
            static fn (ToolOperationException $exception): array => ['orbit.error_code' => 'tool.adoption_unsupported', 'orbit.tool_exception' => $exception],
        ],
        'tool operation without a Tool' => [
            fn (): Throwable => new ToolOperationException('install', 'tool.manager_failed', ToolOutcome::ManagerFailed, 502, 1, 'apt', 'nginx', null, 'apt failed.'),
            502,
            ['code' => 'tool.manager_failed', 'message' => 'apt failed.', 'details' => ['step' => 'install', 'outcome' => 'manager_failed']],
            static fn (ToolOperationException $exception): array => ['orbit.error_code' => 'tool.manager_failed', 'orbit.tool_exception' => $exception],
        ],
        'resource operation' => [
            fn (): Throwable => new ResourceOperationException('route.domain_taken', 'The domain is taken.', 409, details: ['domain' => 'app.test']),
            409,
            ['code' => 'route.domain_taken', 'message' => 'The domain is taken.', 'details' => ['domain' => 'app.test']],
            static fn (): array => ['orbit.error_code' => 'route.domain_taken', 'orbit.error_message' => 'The domain is taken.'],
        ],
        'role assignment' => [
            fn (): Throwable => new RoleAssignmentException('The role is assigned elsewhere.'),
            422,
            ['code' => 'node.role_conflict', 'message' => 'The role is assigned elsewhere.', 'details' => []],
            static fn (): array => [],
        ],
    ];
});
