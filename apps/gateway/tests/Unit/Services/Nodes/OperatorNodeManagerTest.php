<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\OperatorNodeManagementException;
use App\Services\Nodes\OperatorNodeManager;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe(OperatorNodeManager::class, function (): void {
    afterEach(function (): void {
        request()->headers->remove(ExplicitRemoteShellFallback::HEADER);
    });

    it('fails with the documented code when the operator node has no WireGuard address', function (): void {
        $node = Node::factory()
            ->operator()
            ->create([
                'name' => 'mini',
                'wireguard_address' => '10.44.0.24',
                'status' => 'active',
            ]);
        $node->forceFill(['wireguard_address' => null])->save();
        $shell = new OperatorNodeManagerRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        try {
            app(OperatorNodeManager::class)->manage($node->fresh(), 'nicky', 'macos_15-5');
        } catch (OperatorNodeManagementException $exception) {
            expect($exception->errorCode)->toBe('node.wireguard_address_missing')->and($shell->scripts)->toBe([]);

            return;
        }

        throw new RuntimeException('Expected operator node management to fail.');
    });

    it('requires explicit transitional SSH fallback before mutating operator node management state', function (): void {
        $node = Node::factory()
            ->operator()
            ->create([
                'name' => 'mini',
                'user' => null,
                'platform' => null,
                'wireguard_address' => '10.44.0.24',
                'status' => 'active',
            ]);
        $shell = new OperatorNodeManagerRecordingShell;
        app()->instance(RemoteShell::class, $shell);

        try {
            app(OperatorNodeManager::class)->manage($node->fresh(), 'nicky', 'macos_15-5');
        } catch (OperatorNodeManagementException $exception) {
            expect($exception->errorCode)
                ->toBe('node_transport_required')
                ->and($exception->getMessage())
                ->toContain('requires explicit --node-transport=transitional-ssh-fallback')
                ->and($shell->scripts)
                ->toBe([])
                ->and($node->fresh()->user)
                ->toBeNull()
                ->and($node->fresh()->platform)
                ->toBeNull();

            return;
        }

        throw new RuntimeException('Expected operator node management to require explicit transport fallback.');
    });
});

final class OperatorNodeManagerRecordingShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
