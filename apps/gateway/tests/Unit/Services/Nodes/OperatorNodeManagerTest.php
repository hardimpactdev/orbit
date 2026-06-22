<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Nodes\OperatorNodeManagementException;
use App\Services\Nodes\OperatorNodeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe(OperatorNodeManager::class, function (): void {
    it('fails with the documented code when the operator node has no WireGuard address', function (): void {
        $node = Node::factory()->operator()->create([
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
            expect($exception->errorCode)->toBe('node.wireguard_address_missing')
                ->and($shell->scripts)->toBe([]);

            return;
        }

        throw new RuntimeException('Expected operator node management to fail.');
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
