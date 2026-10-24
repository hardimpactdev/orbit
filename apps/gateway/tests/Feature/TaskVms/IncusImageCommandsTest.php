<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\GuestAgentCommandRunner;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\IncusTaskVmImageBuilder;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeGuestAgentCommandRunner;
use Tests\Support\TaskVmSettingsBuilder;

it('sends builder preparation, publication, and alias intent through the selected host and guest runners', function (): void {
    $node = new Node(['id' => 123, 'name' => 'host', 'architecture' => 'x86_64']);
    $host = TaskVmSettingsBuilder::host(123);
    $pin = str_repeat('a', 64);
    $agents = new FakeAgentCommandRunner;
    $agents->answer('incus.image', ['ready' => true, 'fingerprint' => $pin])
        ->answer('incus.instance-images', ['instances' => ['builder' => $pin]]);
    $guests = new FakeGuestAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    app()->instance(GuestAgentCommandRunner::class, $guests);
    $image = app(IncusTaskVmBaseImage::class);
    $builder = app(IncusTaskVmImageBuilder::class);
    $shell = $image->shell($host, $node, 'builder');

    $builder->singleBoot($host, $node, $shell);
    expect($builder->publish($host, $node, 'builder', 'owned-build', ['orbit.profile' => 'base']))->toBe($pin);
    $image->promote($host, $node, $pin);
    expect($image->instances($host, $node))->toBe(['builder' => $pin]);

    expect($guests->installs[0]->name)->toBe('builder')->and($guests->calls[0]['command'])->toBe('image.prepare-incus');
    expect($agents->calls[0])->toBe(['node' => 'host', 'command' => 'incus.image', 'input' => ['project' => $host->project, 'operation' => ['kind' => 'prepare_metadata', 'name' => 'builder']]]);
    expect($agents->calls[1]['input']['operation'])->toEqual(['kind' => 'publish', 'name' => 'builder', 'alias' => 'owned-build', 'properties' => (object) ['orbit.profile' => 'base']]);
    expect($agents->calls[2]['input']['operation'])->toBe(['kind' => 'promote', 'alias' => $host->imageAlias, 'fingerprint' => $pin]);
});

it('requires the typed image result to confirm publication', function (array $result): void {
    $agents = new FakeAgentCommandRunner;
    $agents->answer('incus.image', $result);
    app()->instance(AgentCommandRunner::class, $agents);
    expect(fn () => app(IncusTaskVmImageBuilder::class)->publish(TaskVmSettingsBuilder::host(123), new Node(['name' => 'host']), 'builder', 'base'))
        ->toThrow(TaskVmException::class);
})->with([[[]], [['ready' => false, 'fingerprint' => null]], [['ready' => true, 'fingerprint' => 42]], [['ready' => true, 'fingerprint' => null]]]);

it('maps an image host failure without attempting a second path', function (): void {
    $agents = new FakeAgentCommandRunner;
    $agents->refuse('incus.image', 'task_vm.host_command_failed', ['operation' => 'publish', 'exit_code' => 1, 'stderr' => 'fixture refusal']);
    app()->instance(AgentCommandRunner::class, $agents);
    expect(fn () => app(IncusHost::class)->image(TaskVmSettingsBuilder::host(123), new Node(['name' => 'host']), ['kind' => 'publish']))->toThrow(TaskVmException::class, 'fixture refusal');
    expect($agents->commands())->toBe(['incus.image']);
});
