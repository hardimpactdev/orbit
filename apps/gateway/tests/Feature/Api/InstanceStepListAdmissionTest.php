<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceStepListAction;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;

it('records setup intent and returns a keyed Operation without dispatching PHP', function (): void {
    $instance = dev935_instance();
    $instance->node->accessibleNodes()->attach($instance->node_id);
    Queue::fake();
    $this->withServerVariables(['REMOTE_ADDR' => $instance->node->wireguard_ip]);
    $url = '/api/v1/instances/'.$instance->id.'/setup';
    $first = $this->withHeader('Idempotency-Key', 'setup-proof')->postJson($url)->assertAccepted();
    $this->postJson($url)->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    expect(Operation::query()->count())->toBe(1)
        ->and(Operation::query()->sole()->input)->toBe([
            'instance_id' => $instance->id,
            'source' => 'project', 'list' => 'setup',
            'configuration_digest' => hash('sha256', '[]'),
            'step_digests' => [], 'release_directory' => $instance->checkout_path,
            'project_id' => $instance->project_id, 'repository' => $instance->project->repository_url,
        ]);
    Queue::assertNothingPushed();
});

it('keeps Project commands out of admitted setup input and uses one digest rule', function (): void {
    $instance = dev935_instance();
    $steps = app(ProjectLifecycleStepStore::class);
    $steps->create($instance->project, LifecyclePhase::Setup, new LifecycleStep('install', 'synthetic-private-script', 30), null, null);
    $admission = app(StartInstanceStepListAction::class);
    $operation = $admission->execute($instance, LifecyclePhase::Setup, 'operator', null);
    expect($operation->input['configuration_digest'])->toBe($admission->configurationDigest($instance->project, LifecyclePhase::Setup))
        ->and($operation->input['step_digests'])->toBe(['install' => hash('sha256', 'synthetic-private-script')])
        ->and($operation->input['release_directory'])->toBe($instance->checkout_path)
        ->and(json_encode($operation->input))->not->toContain('synthetic-private-script');
});
