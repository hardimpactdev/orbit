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

it('sends fixed owner, boot, and smoke intent through the guest runner', function (): void {
    $guest = AgentGuest::incus(new Node(['name' => 'host', 'architecture' => 'x86_64']), 'orbit-tasks', 'builder');
    $agents = new FakeGuestAgentCommandRunner;
    app()->instance(GuestAgentCommandRunner::class, $agents);
    $recipe = app(TaskVmImageRecipe::class);

    $recipe->owner($guest);
    $recipe->boot($guest, lean: true);
    $recipe->firewall($guest);
    $recipe->check($guest, leanBoot: true);
    $recipe->check($guest, firewall: true);

    expect($agents->installs)->toBe([$guest]);
    expect(array_column($agents->calls, 'command'))->toBe(['image.owner', 'image.boot', 'image.firewall', 'image.check', 'image.check']);
    expect(array_column($agents->calls, 'input'))->toBe([[], ['lean' => true], [], ['lean_boot' => true, 'firewall' => false], ['lean_boot' => false, 'firewall' => true]]);
    expect(file_exists(resource_path('task-vms/image-check.sh')))->toBeFalse();
    expect(file_exists(resource_path('task-vms/lean-boot.sh')))->toBeFalse();
});

it('fails the build on a native smoke refusal', function (): void {
    $guest = AgentGuest::incus(new Node(['architecture' => 'x86_64']), 'orbit-tasks', 'smoke');
    $agents = new FakeGuestAgentCommandRunner;
    $agents->answer = static fn (): array => throw new AgentCommandRefused('image.check', 'task_vm.image_check_failed', 'Wrong account.');
    app()->instance(GuestAgentCommandRunner::class, $agents);
    expect(fn () => app(TaskVmImageRecipe::class)->check($guest))->toThrow(TaskVmException::class, 'Wrong account.');
    expect($agents->calls)->toHaveCount(1);
});

it('sends recipe identity and bounded observations through fixed guest commands', function (): void {
    $guest = AgentGuest::incus(new Node(['architecture' => 'x86_64']), 'orbit-tasks', 'builder');
    $agents = new FakeGuestAgentCommandRunner;
    $recipe = new TaskVmImageRecipe($agents);

    $recipe->mark($guest, 'sha256:'.str_repeat('a', 64), 'base');
    $recipe->log($guest, 'base', 'packages', str_repeat('x', 70000).'tail');

    expect($agents->calls[0])->toBe(['guest' => $guest, 'command' => 'image.recipe-marker', 'input' => ['identity' => 'sha256:'.str_repeat('a', 64), 'profile' => 'base']]);
    expect($agents->calls[1]['command'])->toBe('image.recipe-log')
        ->and($agents->calls[1]['input']['profile'])->toBe('base')
        ->and($agents->calls[1]['input']['phase'])->toBe('packages')
        ->and(strlen($agents->calls[1]['input']['text']))->toBe(65536)
        ->and($agents->calls[1]['input']['text'])->toEndWith('tail');
});

it('fails recipe record writes on native refusal or missing confirmation', function (bool $refused): void {
    $guest = AgentGuest::incus(new Node(['architecture' => 'x86_64']), 'orbit-tasks', 'builder');
    $agents = new FakeGuestAgentCommandRunner;
    $agents->answer = static fn (): array => $refused ? throw new AgentCommandRefused('image.recipe-marker', 'task_vm.image_build_failed', 'Foreign marker.') : [];

    expect(fn () => (new TaskVmImageRecipe($agents))->mark($guest, 'sha256:'.str_repeat('a', 64), 'base'))->toThrow(TaskVmException::class);
    expect($agents->calls)->toHaveCount(1);
})->with([true, false]);

it('keeps cloud image execution behind the guest agent boundary', function (): void {
    $source = file_get_contents(app_path('Infrastructure/TaskVms/UpCloudTaskVmImageBuilder.php'));
    foreach (['RemoteCommand', 'asRoot', 'asUser', 'CommandResult', 'NodeFirewallRuleCatalog'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
    expect(file_exists(app_path('Infrastructure/TaskVms/TaskVmGuestShell.php')))->toBeFalse();
    expect(file_exists(app_path('Infrastructure/TaskVms/UpCloudGuestShell.php')))->toBeFalse();
});
