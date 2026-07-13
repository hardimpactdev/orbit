<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Convergence\SystemdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {});

it('plans ok when the remote systemd service already matches intent', function (): void {
    $content = "[Unit]\nDescription=Orbit process node-exporter\n";
    $service = new SystemdService(
        unitName: 'node-exporter',
        content: $content,
    );
    $node = systemd_service_node('10.48.0.21');
    Http::preventStrayRequests();
    Http::fake([
        'http://10.48.0.21:9477/v1/commands' => systemd_service_agent_response(
            operationId: 'systemd-service.probe',
            data: [
                'exists' => true,
                'hash' => hash('sha256', $content),
                'enabled' => true,
            ],
        ),
    ]);

    $probe = $service->probe($node);
    $plan = $service->plan($probe);
    $result = $service->apply($node, $plan);

    expect($probe->exists)
        ->toBeTrue()
        ->and($probe->enabled)
        ->toBeTrue()
        ->and($probe->hash)
        ->toBe(hash('sha256', $content))
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($result->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($result->changed())
        ->toBeFalse();

    Http::assertSent(fn (Request $request): bool => systemd_service_request_matches(
        request: $request,
        action: 'probe',
        operationId: 'systemd-service.probe',
        service: 'node-exporter.service',
    ));
});

it('applies a missing systemd service unit and enables it', function (): void {
    $content = "[Unit]\nDescription=Orbit process opencode-server\n";
    $service = new SystemdService(
        unitName: 'opencode-server',
        content: $content,
    );
    $node = systemd_service_node('10.48.0.22');
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(systemd_service_agent_payload(
            operationId: 'systemd-service.probe',
            data: [
                'exists' => false,
                'hash' => null,
                'enabled' => false,
            ],
        ))
        ->push(systemd_service_agent_payload(
            operationId: 'systemd-service.apply',
            data: [
                'action' => 'apply',
                'service' => 'opencode-server.service',
                'status' => 'changed',
                'changed' => true,
            ],
        ));

    $probe = $service->probe($node);
    $plan = $service->plan($probe);
    $result = $service->apply($node, $plan);

    expect($plan->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($plan->details)
        ->toMatchArray([
            'service' => 'opencode-server.service',
            'path' => '/etc/systemd/system/opencode-server.service',
            'expected_hash' => hash('sha256', $content),
            'enabled' => true,
            'observed_hash' => null,
            'observed_enabled' => false,
        ])
        ->and($result->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())
        ->toBeTrue();

    Http::assertSent(fn (Request $request): bool => systemd_service_request_matches(
        request: $request,
        action: 'apply',
        operationId: 'systemd-service.apply',
        service: 'opencode-server.service',
        content: $content,
        enabled: true,
    ));
});

it('plans a changed systemd service when it is disabled', function (): void {
    $content = "[Unit]\nDescription=Orbit process queue\n";
    $service = new SystemdService(
        unitName: 'orbit_docs_main_queue',
        content: $content,
    );
    $node = systemd_service_node('10.48.0.23');
    Http::preventStrayRequests();
    Http::fake([
        'http://10.48.0.23:9477/v1/commands' => systemd_service_agent_response(
            operationId: 'systemd-service.probe',
            data: [
                'exists' => true,
                'hash' => hash('sha256', $content),
                'enabled' => false,
            ],
        ),
    ]);

    $plan = $service->plan($service->probe($node));

    expect($plan->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($plan->summary)
        ->toBe('Enable systemd service orbit_docs_main_queue.service.')
        ->and($plan->details)
        ->toMatchArray([
            'service' => 'orbit_docs_main_queue.service',
            'observed_enabled' => false,
        ]);
});

it('reports unreachable when probing the systemd service cannot reach the node', function (): void {
    $node = systemd_service_node('10.48.0.24');
    $service = new SystemdService(
        unitName: 'node-exporter',
        content: "[Unit]\nDescription=Orbit process node-exporter\n",
    );
    Http::preventStrayRequests();
    Http::fake([
        'http://10.48.0.24:9477/v1/commands' => systemd_service_agent_response(
            operationId: 'systemd-service.probe',
            data: [],
            exitCode: 1,
            frameType: 'stderr',
            message: 'agent unavailable',
        ),
    ]);

    $probe = $service->probe($node);
    $plan = $service->plan($probe);

    expect($probe->reachable)
        ->toBeFalse()
        ->and($probe->error)
        ->toBe('agent unavailable')
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Unreachable)
        ->and($plan->summary)
        ->toBe('Could not inspect systemd service node-exporter.service.');
});

function systemd_service_node(string $wireguardAddress): Node
{
    /** @var Node $node */
    $node = Node::factory()->create([
        'wireguard_address' => $wireguardAddress,
        'managed' => true,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $data
 */
function systemd_service_agent_response(
    string $operationId,
    array $data,
    int $exitCode = 0,
    string $frameType = 'stdout',
    ?string $message = null,
): mixed {
    return Http::response(systemd_service_agent_payload($operationId, $data, $exitCode, $frameType, $message));
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function systemd_service_agent_payload(
    string $operationId,
    array $data,
    int $exitCode = 0,
    string $frameType = 'stdout',
    ?string $message = null,
): array {
    $message ??= json_encode([
        'success' => [
            'data' => $data,
            'meta' => [],
        ],
    ], JSON_THROW_ON_ERROR);

    return [
        'transport' => 'agent-push',
        'operation_id' => $operationId,
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => $frameType,
                'message' => $message,
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ];
}

function systemd_service_request_matches(
    Request $request,
    string $action,
    string $operationId,
    string $service,
    ?string $content = null,
    ?bool $enabled = null,
): bool {
    /** @var mixed $argv */
    $argv = $request['argv'];
    /** @var mixed $input */
    $input = $request['input'] ?? null;
    $normalizedInput = is_string($input) ? str_replace('\/', '/', $input) : '';

    return (
        is_array($argv)
        && $request['binary'] === 'orbit'
        && agentPushRequestOperationIdMatchesToken($request)
        && ($argv[0] ?? null) === 'internal:process-systemd-service'
        && ($argv[1] ?? null) === $action
        && ($argv[2] ?? null) === $service
        && is_string($argv[3] ?? null)
        && str_starts_with($argv[3], '--operation-token=')
        && ($argv[4] ?? null) === '--json'
        && ($content === null || str_contains($normalizedInput, 'Orbit process'))
        && ($enabled === null || str_contains($normalizedInput, '"enabled":'.($enabled ? 'true' : 'false')))
    );
}

final class SystemdServiceUnexpectedShell implements RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(1, '', 'legacy shell should not be used', 1);
    }
}
