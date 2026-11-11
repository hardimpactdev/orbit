<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Saloon\Http\Faking\MockResponse;

/** @return array<string, mixed> */
function instance_payload(?array $removal = null): array
{
    return [
        'id' => 5,
        'project_id' => 3,
        'node_id' => 2,
        'project' => ['id' => 3, 'name' => 'Orbit docs', 'slug' => 'orbit-docs'],
        'node' => ['id' => 2, 'name' => 'beast'],
        'vite_port' => null,
        'ssr_port' => null,
        'name' => 'dev',
        'source_layout' => 'checkout',
        'checkout_path' => '/home/orbit/apps/orbit-docs/dev',
        'production_user' => null,
        'production_home' => null,
        'root' => null,
        'effective_root' => 'public',
        'selected_branch' => 'dev',
        'deployment_branch' => null,
        'branch_override' => null,
        'starting_commit' => str_repeat('a', times: 40),
        'seed_path' => null,
        'seed_commit' => null,
        'detached' => false,
        'status' => $removal === null ? 'active' : 'removing',
        'route' => instance_route_payload(),
        'routes' => [instance_route_payload()],
        'domain' => 'dev.orbit.test',
        'url' => 'https://dev.orbit.test',
        'removal' => $removal,
        'transfer' => null,
        'attached_targets' => [],
        'deploy_steps' => [],
        'auto_deploy' => instance_auto_deploy_payload(),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function instance_auto_deploy_payload(array $overrides = []): array
{
    return [
        'enabled' => false,
        'gate' => null,
        'warning' => null,
        'result' => null,
        'commit' => null,
        'head' => null,
        'since' => null,
        'error_code' => null,
        'message' => null,
        'pause' => null,
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function registration_payload(): array
{
    return [
        ...instance_payload(),
        'name' => 'default',
        'checkout_path' => '/home/orbit/apps/acme/default',
        'selected_branch' => 'main',
        'deployment_branch' => null,
    ];
}

function registration_mock_response(): MockResponse
{
    return MockResponse::make([
        'data' => registration_payload(),
        'meta' => ['request_id' => instance_request_id()],
    ], 201);
}

function registration_json(): string
{
    return json_encode([
        ...registration_payload(),
        'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
        'routes' => [[...instance_route_payload(), 'request_id' => instance_request_id()]],
        'request_id' => instance_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/**
 * @param  array<string, mixed>|null  $result
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function instance_operation_payload(string $kind, ?array $result = null, array $overrides = []): array
{
    return [
        'id' => 901,
        'kind' => $kind,
        'target_type' => 'instance',
        'target_id' => 5,
        'input' => [],
        'status' => 'succeeded',
        'sequence' => 8,
        'current_step' => null,
        'current_step_started_at' => null,
        'current_step_observed_at' => null,
        'failed_step' => null,
        'error_code' => null,
        'error' => null,
        'result' => $result ?? match ($kind) {
            'instance.destroy' => ['removed' => true],
            default => ['instance_id' => 5, 'ready' => true, 'commit' => str_repeat('a', 40)],
        },
        'cancel_requested' => false,
        'attempts' => 1,
        'triggered_by' => 'node:2',
        'caller_node_id' => 2,
        'caller_node_name' => 'beast',
        'created_at' => '2026-10-29T10:00:00+00:00',
        'started_at' => '2026-10-29T10:00:00+00:00',
        'finished_at' => '2026-10-29T10:00:01+00:00',
        'poll_after_seconds' => null,
        'capacity_wait' => null,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>|null  $result
 * @param  array<string, mixed>  $overrides
 * @return array<class-string, MockResponse>
 */
function instance_operation_responses(string $request, string $kind, ?array $result = null, array $overrides = [], ?string $requestId = null): array
{
    $body = ['data' => instance_operation_payload($kind, $result, $overrides),
        'meta' => ['request_id' => $requestId ?? instance_request_id()]];

    return [
        $request => MockResponse::make($body, 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make($body),
    ];
}

/** @param array<string, mixed>|null $result */
function instance_operation_json(string $kind, ?array $result = null, array $overrides = [], ?string $requestId = null): string
{
    $payload = instance_operation_payload($kind, $result, $overrides);
    $payload['input'] = new stdClass;

    return json_encode([...$payload, 'overdue_since' => null, 'request_id' => $requestId ?? instance_request_id()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** @return array<string, mixed> */
function instance_route_payload(): array
{
    return [
        'id' => 8,
        'kind' => 'app',
        'project_id' => 3,
        'node_id' => 2,
        'cluster_id' => null,
        'generation_basis_node_id' => 2,
        'domain' => 'dev.orbit.test',
        'provenance' => 'generated',
        'publication' => 'private',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
        'replaces_route_id' => null,
        'replaced_by_route_id' => null,
        'replacement_step' => null,
        'target_set_step' => null,
        'target' => ['id' => 9, 'instance_id' => 5, 'position' => 0],
        'targets' => [['id' => 9, 'instance_id' => 5, 'position' => 0]],
        'process_id' => null,
        'upstream' => null,
        'web_root' => null,
    ];
}

function instance_mock_response(int $status = 200, ?array $payload = null): MockResponse
{
    return MockResponse::make([
        'data' => $payload ?? instance_payload(),
        'meta' => ['request_id' => instance_request_id()],
    ], $status);
}

/** @return array<string, mixed> */
function removal_progress_payload(bool $force = false): array
{
    return [
        'operation_id' => '0198e15d-16c4-7855-8eb2-182b53ad28bb',
        'id' => 5,
        'name' => 'dev',
        'force' => $force,
        'status' => 'failed',
        'current_step' => 'runtime_cleanup',
        'total' => 1,
        'completed' => 0,
        'remaining' => 1,
        'failed_step' => 'runtime_cleanup',
        'error_code' => 'instance.runtime_interrupted',
    ];
}

function instance_json(): string
{
    return json_encode([
        ...instance_payload(),
        'route' => [...instance_route_payload(), 'request_id' => instance_request_id()],
        'routes' => [[...instance_route_payload(), 'request_id' => instance_request_id()]],
        'request_id' => instance_request_id(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function instance_request_id(): string
{
    return '0198e15d-16c4-7855-8eb2-182b53ad28ba';
}
