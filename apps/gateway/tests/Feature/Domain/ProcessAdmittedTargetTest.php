<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Node;
use App\Models\Process;

it('captures an admitted provisioning Process target without resolving an active Node', function (): void {
    $node = Node::query()->create(['name' => 'provisioning-process', 'platform' => 'linux', 'status' => 'provisioning', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'admitted-process', 'runtime' => 'systemd', 'restart_policy' => 'never', 'desired_state' => 'stopped', 'status' => 'provisioning', 'runtime_config' => []]);
    $process->update(['runtime' => 'systemd', 'working_directory' => '/home/orbit', 'runtime_config' => [
        'command' => ['/usr/bin/true'], 'environment' => ['PASSWORD' => 'private-provisioning-value'],
    ]]);
    $target = new ProcessTarget(node: $node, user: 'orbit', checkoutPath: '/home/orbit');
    $admission = app(ProcessOperationAdmission::class);
    expect(fn () => $admission->inputFor($process, 'create'))->toThrow(ResourceOperationException::class);
    $input = $admission->inputFor($process, 'create', created: true, target: $target);
    $renderer = app(SystemdProcessRenderer::class);
    expect($input['node_id'])->toBe($node->id)
        ->and($input['created'])->toBeTrue()
        ->and($input['activation_pending'])->toBeFalse()
        ->and($input['content_hash'])->toBe(hash('sha256', $renderer->render($process, $target)))
        ->and(json_encode($input))->not->toContain('private-provisioning-value');
});
