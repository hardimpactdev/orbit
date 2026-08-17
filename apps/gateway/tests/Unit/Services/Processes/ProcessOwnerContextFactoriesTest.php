<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Processes\ProcessOwnerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('constructs a node-owned host process context', function (): void {
    $node = Node::factory()->create([
        'name' => 'app-host-1',
        'platform' => 'ubuntu_24-04',
    ]);

    $context = ProcessOwnerContext::forNode($node);

    expect($context->node->is($node))
        ->toBeTrue()
        ->and($context->app)
        ->toBeNull()
        ->and($context->workspace)
        ->toBeNull()
        ->and($context->instance)
        ->toBeNull()
        ->and($context->owner->is($node))
        ->toBeTrue()
        ->and($context->runtimeApp())
        ->toBeNull()
        ->and($context->eventApp())
        ->toBeNull()
        ->and($context->subject()->is($node))
        ->toBeTrue()
        ->and($context->label())
        ->toBe("node 'app-host-1'")
        ->and($context->payloadContext())
        ->toBe([
            'node' => 'app-host-1',
            'app' => null,
            'instance' => null,
            'workspace' => null,
        ]);
});

it('constructs an instance-owned context and derives the app from the instance', function (): void {
    $node = Node::factory()->create(['name' => 'app-host-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development']);

    $context = ProcessOwnerContext::forInstance($node, $instance);

    expect($context->node->is($node))
        ->toBeTrue()
        ->and($context->app?->is($app))
        ->toBeTrue()
        ->and($context->workspace)
        ->toBeNull()
        ->and($context->instance?->is($instance))
        ->toBeTrue()
        ->and($context->owner->is($app))
        ->toBeTrue()
        ->and($context->runtimeApp()?->is($app))
        ->toBeTrue()
        ->and($context->eventApp()?->is($app))
        ->toBeTrue()
        ->and($context->subject()->is($instance))
        ->toBeTrue()
        ->and($context->label())
        ->toBe("instance 'docs.development'");
});

it('constructs a workspace-owned context and derives the app from the instance', function (): void {
    $node = Node::factory()->create(['name' => 'app-host-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create(['name' => 'development']);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'instance_id' => $instance->id,
        'name' => 'feature-docs',
    ]);

    $context = ProcessOwnerContext::forWorkspace($node, $workspace, $instance);

    expect($context->node->is($node))
        ->toBeTrue()
        ->and($context->app?->is($app))
        ->toBeTrue()
        ->and($context->workspace?->is($workspace))
        ->toBeTrue()
        ->and($context->instance?->is($instance))
        ->toBeTrue()
        ->and($context->owner->is($workspace))
        ->toBeTrue()
        ->and($context->runtimeApp()?->is($app))
        ->toBeTrue()
        ->and($context->subject()->is($instance))
        ->toBeTrue()
        ->and($context->label())
        ->toBe("workspace 'feature-docs' on instance 'docs.development'");
});

it('keeps the constructor private so mixed ownership cannot be constructed', function (): void {
    $constructor = new ReflectionClass(ProcessOwnerContext::class)->getConstructor();

    expect($constructor)
        ->not
        ->toBeNull()
        ->and($constructor->isPrivate())
        ->toBeTrue();
});

it('rejects an instance-owned context when the instance has no app', function (): void {
    $node = Node::factory()->create(['name' => 'app-host-1']);
    $instance = new Instance(['name' => 'orphan']);

    expect(fn (): ProcessOwnerContext => ProcessOwnerContext::forInstance($node, $instance))
        ->toThrow(
            InvalidArgumentException::class,
            'An app-owned process context requires an instance attached to an app.',
        );
});

it('rejects a workspace-owned context when the instance has no app', function (): void {
    $node = Node::factory()->create(['name' => 'app-host-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $workspace = Workspace::factory()->for($app, 'app')->make(['name' => 'feature-docs']);
    $instance = new Instance(['name' => 'orphan']);

    expect(fn (): ProcessOwnerContext => ProcessOwnerContext::forWorkspace($node, $workspace, $instance))
        ->toThrow(
            InvalidArgumentException::class,
            'A workspace-owned process context requires an instance attached to an app.',
        );
});

it('rejects a workspace-owned context when the instance does not belong to the workspace', function (): void {
    $node = Node::factory()->create(['name' => 'app-host-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $workspaceInstance = Instance::factory()->for($app)->create(['name' => 'development']);
    $otherInstance = Instance::factory()->for($app)->create(['name' => 'staging']);
    $workspace = Workspace::factory()->for($app, 'app')->create([
        'instance_id' => $workspaceInstance->id,
        'name' => 'feature-docs',
    ]);

    expect(fn (): ProcessOwnerContext => ProcessOwnerContext::forWorkspace($node, $workspace, $otherInstance))
        ->toThrow(
            InvalidArgumentException::class,
            'A workspace-owned process context requires the workspace instance.',
        );
});
