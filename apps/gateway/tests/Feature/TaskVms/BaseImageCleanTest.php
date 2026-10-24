<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Infrastructure\TaskVms\TaskVmImageRecipe;
use App\Models\Node;
use Tests\Support\FakeGuestAgentCommandRunner;

it('installs the pinned agent then requests typed builder cleanup', function (): void {
    $guest = AgentGuest::incus(new Node(['name' => 'host', 'architecture' => 'x86_64']), 'orbit-tasks', 'tvm-image-1');
    $agents = new FakeGuestAgentCommandRunner;
    app()->instance(GuestAgentCommandRunner::class, $agents);

    app(TaskVmImageRecipe::class)->clean($guest);

    expect($agents->installs)->toBe([$guest]);
    expect($agents->calls)->toBe([['guest' => $guest, 'command' => 'image.clean', 'input' => []]]);
});

it('fails the build on cleanup refusal or absent confirmation', function (string $result): void {
    $guest = AgentGuest::incus(new Node(['name' => 'host', 'architecture' => 'x86_64']), 'orbit-tasks', 'tvm-image-1');
    $agents = new FakeGuestAgentCommandRunner;
    $agents->answer = static fn (): array => $result === 'missing' ? [] : throw new AgentCommandRefused('image.clean', $result, 'Cleanup refused.');
    app()->instance(GuestAgentCommandRunner::class, $agents);

    expect(fn () => app(TaskVmImageRecipe::class)->clean($guest))->toThrow(TaskVmException::class);
    expect($agents->calls)->toHaveCount(1);
})->with(['task_vm.image_clean_failed', 'task_vm.image_identity_present', 'missing']);

it('keeps image cleanup behind the native command boundary', function (): void {
    $lines = file(app_path('Infrastructure/TaskVms/TaskVmImageRecipe.php'));
    $method = new ReflectionMethod(TaskVmImageRecipe::class, 'clean');
    $source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    foreach (['SshExecutor', 'RemoteCommand', 'TaskVmScripts', 'asRoot', 'bash'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
    expect(file_exists(resource_path('task-vms/base-image-clean.sh')))->toBeFalse();
});
