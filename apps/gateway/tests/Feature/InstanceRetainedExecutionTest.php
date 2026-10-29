<?php

declare(strict_types=1);

use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\LifecycleAgent;

it('keeps native lifecycle lists inside their caller transaction with the full step timeout', function (LifecyclePhase $phase): void {
    $instance = dev935_instance();
    app(ProjectLifecycleStepStore::class)->create($instance->project, $phase, new LifecycleStep('long-install', 'composer install --no-interaction', 540), null, null);
    $agent = new LifecycleAgent;
    $runner = $agent->runner();
    expect(DB::transaction(fn (): bool => $runner->run($instance, $phase)))->toBeTrue();
    expect($agent->inputs)->toHaveCount(1)->and($agent->inputs[0]['timeout'])->toBe(540)
        ->and($agent->inputs[0]['checkout'])->toBe($instance->checkout_path);
    expect(Operation::query()->count())->toBe(0);
})->with([LifecyclePhase::Setup, LifecyclePhase::Teardown]);
