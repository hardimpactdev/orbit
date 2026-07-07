<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Processes\RemoteLaunchdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('dispatches launchd apply through the typed local executor command', function (): void {
    $shell = new RemoteLaunchdServiceRecordingShell(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'success' => [
                'data' => [
                    'status' => 'ok',
                    'summary' => 'Launchd plist already matches.',
                    'details' => ['observed_hash' => 'abc123'],
                ],
                'meta' => [],
            ],
        ], JSON_THROW_ON_ERROR)
            ."\n",
        stderr: '',
        durationMs: 1,
    ));
    app()->instance(RemoteShell::class, $shell);

    $result = app(RemoteLaunchdService::class)->apply(
        node: remote_launchd_service_node(),
        label: 'dev.hardimpact.orbit.orbit_docs_main_queue',
        content: '<plist version="1.0"></plist>',
    );

    expect($result->status)
        ->toBe(ConvergenceStatus::Ok)
        ->and($result->summary)
        ->toBe('Launchd plist already matches.')
        ->and($shell->calls)
        ->toHaveCount(1)
        ->and($shell->calls[0]['script'])
        ->toContain("internal:process-launchd-service 'apply' 'dev.hardimpact.orbit.orbit_docs_main_queue'")
        ->toContain('--operation-token=')
        ->toContain('--json')
        ->and($shell->operationId(0))
        ->toBe('process.launchd.apply')
        ->and($shell->calls[0]['options']['input'])
        ->toBe(json_encode([
            'content' => '<plist version="1.0"></plist>',
            'enabled' => true,
        ], JSON_THROW_ON_ERROR));
});

it('dispatches launchd remove with the plist path as a positional argument', function (): void {
    $shell = new RemoteLaunchdServiceRecordingShell(new RemoteShellResult(
        exitCode: 0,
        stdout: json_encode([
            'success' => [
                'data' => [
                    'action' => 'remove',
                    'changed' => true,
                ],
                'meta' => [],
            ],
        ], JSON_THROW_ON_ERROR)
            ."\n",
        stderr: '',
        durationMs: 1,
    ));
    app()->instance(RemoteShell::class, $shell);

    $removed = app(RemoteLaunchdService::class)->remove(
        node: remote_launchd_service_node(),
        label: 'dev.hardimpact.orbit.orbit_docs_main_queue',
        plistPath: '/Users/orbit/Library/LaunchAgents/dev.hardimpact.orbit.orbit_docs_main_queue.plist',
    );

    expect($removed)
        ->toBeTrue()
        ->and($shell->calls)
        ->toHaveCount(1)
        ->and($shell->calls[0]['script'])
        ->toContain(
            "internal:process-launchd-service 'remove' 'dev.hardimpact.orbit.orbit_docs_main_queue' '/Users/orbit/Library/LaunchAgents/dev.hardimpact.orbit.orbit_docs_main_queue.plist'",
        )
        ->and($shell->operationId(0))
        ->toBe('process.launchd.remove');
});

function remote_launchd_service_node(): Node
{
    $node = Node::factory()->create([
        'name' => 'mac-app',
        'host' => 'mac-app.example.com',
        'wireguard_address' => '10.44.0.81',
        'user' => 'orbit',
        'platform' => 'macos_26-5-1',
        'host_key_type' => 'ssh-ed25519',
        'host_key_public' => 'AAAAC3NzaC1lZDI1NTE5AAAAIRemoteLaunchdServicePinnedKey',
        'host_key_fingerprint' => 'SHA256:remote-launchd-service',
        'host_key_pin_mode' => 'verified',
        'host_key_pinned_at' => now(),
    ]);
    if (! $node instanceof Node) {
        throw new RuntimeException('Node factory did not return a Node.');
    }

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @mago-expect lint:file-name
 */
final class RemoteLaunchdServiceRecordingShell implements RemoteShell
{
    /**
     * @var list<array{node: Node, script: string, options: array{metadata: array{ORBIT_OPERATION_ID: string|null}, input: string|null}}>
     */
    public array $calls = [];

    public function __construct(
        private readonly RemoteShellResult $result,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->calls[] = [
            'node' => $node,
            'script' => $script,
            'options' => [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => $this->operationIdFromOptions($options),
                ],
                'input' => $this->inputFromOptions($options),
            ],
        ];

        return $this->result;
    }

    public function operationId(int $index): ?string
    {
        return $this->calls[$index]['options']['metadata']['ORBIT_OPERATION_ID'];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function operationIdFromOptions(array $options): ?string
    {
        if (! array_key_exists('metadata', $options) || ! is_array($options['metadata'])) {
            return null;
        }

        if (! array_key_exists('ORBIT_OPERATION_ID', $options['metadata'])) {
            return null;
        }

        return is_string($options['metadata']['ORBIT_OPERATION_ID'])
            ? $options['metadata']['ORBIT_OPERATION_ID']
            : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function inputFromOptions(array $options): ?string
    {
        if (! array_key_exists('input', $options)) {
            return null;
        }

        return is_string($options['input']) ? $options['input'] : null;
    }
}
