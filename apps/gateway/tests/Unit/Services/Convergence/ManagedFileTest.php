<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Convergence\ManagedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {});

it('plans ok when the remote managed file already matches intent', function (): void {
    $content = "grafana: enabled\n";
    $file = new ManagedFile(
        path: '/etc/orbit/grafana.yml',
        content: $content,
        mode: '0640',
    );
    $node = managed_file_node('10.47.0.21');
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.21:9477/v1/commands' => managed_file_agent_response(
            operationId: 'managed-file.probe',
            data: [
                'exists' => true,
                'hash' => hash('sha256', $content),
                'mode' => '0640',
            ],
        ),
    ]);

    $probe = $file->probe($node);
    $plan = $file->plan($probe);
    $result = $file->apply($node, $plan);

    expect($probe->exists)
        ->toBeTrue()
        ->and($probe->hash)
        ->toBe(hash('sha256', $content))
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($result->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($result->changed())
        ->toBeFalse();

    Http::assertSent(fn (Request $request): bool => managed_file_request_matches(
        request: $request,
        action: 'probe',
        operationId: 'managed-file.probe',
        path: '/etc/orbit/grafana.yml',
    ));
});

it('applies a missing managed file through a redacted remote shell script', function (): void {
    $file = new ManagedFile(
        path: '/etc/orbit/secrets/app.env',
        content: "TOKEN=secret-value\n",
        mode: '0600',
        directoryMode: '0700',
        sensitive: true,
    );
    $node = managed_file_node('10.47.0.22');
    Http::preventStrayRequests();
    Http::fakeSequence()
        ->push(managed_file_agent_payload(
            operationId: 'managed-file.probe',
            data: [
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ],
        ))
        ->push(managed_file_agent_payload(
            operationId: 'managed-file.write',
            data: [
                'path' => '/etc/orbit/secrets/app.env',
                'hash' => hash('sha256', "TOKEN=secret-value\n"),
                'mode' => '0600',
            ],
        ));

    $probe = $file->probe($node);
    $plan = $file->plan($probe);
    $result = $file->apply($node, $plan);

    expect($plan->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($result->status)
        ->toBe(ConvergenceStatus::Changed)
        ->and($result->changed())
        ->toBeTrue()
        ->and($result->details['path'])
        ->toBe('/etc/orbit/secrets/app.env')
        ->and($result->details)
        ->not->toHaveKey('content');

    Http::assertSent(fn (Request $request): bool => managed_file_request_matches(
        request: $request,
        action: 'write',
        operationId: 'managed-file.write',
        path: '/etc/orbit/secrets/app.env',
        content: "TOKEN=secret-value\n",
        mode: '0600',
        directoryMode: '0700',
    ));
});

it('reports unreachable when probing the managed file cannot reach the node', function (): void {
    $node = managed_file_node('10.47.0.23');
    $file = new ManagedFile(
        path: '/etc/orbit/missing.conf',
        content: "enabled=true\n",
    );
    Http::preventStrayRequests();
    Http::fake([
        'http://10.47.0.23:9477/v1/commands' => managed_file_agent_response(
            operationId: 'managed-file.probe',
            data: [],
            exitCode: 1,
            frameType: 'stderr',
            message: 'agent unavailable',
        ),
    ]);

    $probe = $file->probe($node);
    $plan = $file->plan($probe);

    expect($probe->reachable)
        ->toBeFalse()
        ->and($probe->error)
        ->toBe('agent unavailable')
        ->and($plan->status)
        ->toBe(ConvergenceStatus::Unreachable)
        ->and($plan->summary)
        ->toBe('Could not inspect managed file /etc/orbit/missing.conf.');
});

function managed_file_node(string $wireguardAddress): Node
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
function managed_file_agent_response(
    string $operationId,
    array $data,
    int $exitCode = 0,
    string $frameType = 'stdout',
    ?string $message = null,
): mixed {
    return Http::response(managed_file_agent_payload($operationId, $data, $exitCode, $frameType, $message));
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function managed_file_agent_payload(
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

function managed_file_request_matches(
    Request $request,
    string $action,
    string $operationId,
    string $path,
    ?string $content = null,
    ?string $mode = null,
    ?string $directoryMode = null,
): bool {
    /** @var mixed $argv */
    $argv = $request['argv'];
    /** @var mixed $input */
    $input = $request['input'];
    $normalizedInput = is_string($input) ? str_replace('\/', '/', $input) : '';
    $inputMatches =
        $normalizedInput !== ''
        && str_contains($normalizedInput, $path)
        && ($content === null || str_contains($normalizedInput, trim($content)))
        && ($mode === null || str_contains($normalizedInput, "\"mode\":\"{$mode}\""))
        && ($directoryMode === null || str_contains($normalizedInput, "\"directory_mode\":\"{$directoryMode}\""));

    return (
        is_array($argv)
        && $inputMatches
        && $request['binary'] === 'orbit'
        && agentPushRequestOperationIdMatchesToken($request)
        && ($argv[0] ?? null) === 'internal:managed-file'
        && ($argv[1] ?? null) === $action
        && is_string($argv[2] ?? null)
        && str_starts_with($argv[2], '--operation-token=')
        && ($argv[3] ?? null) === '--json'
    );
}

final class ManagedFileUnexpectedShell implements \App\Contracts\RemoteShell
{
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return new RemoteShellResult(1, '', 'legacy shell should not be used', 1);
    }
}
