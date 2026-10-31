<?php

declare(strict_types=1);

use App\Domain\Metrics\AdmitMetricsFleetAction;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Models\Operation;

it('holds native fleet activation in production source and keeps existing parent composition', function (): void {
    config(['swamp.direct_writers' => ['metrics.reconcile' => 'direct']]);
    $direct = app(DirectOperation::class);
    expect($direct->definition('metrics.reconcile'))->toBeNull();
    expect($direct->writer('metrics.reconcile'))->toBeNull();
    expect(app(OperationKinds::class)->directOnly('metrics.reconcile'))->toBeFalse();
    expect(app(MetricsFleetReconciler::class))->toBeInstanceOf(NativeMetricsFleetReconciler::class);
    $before = Operation::query()->count();
    expect(fn () => app(AdmitMetricsFleetAction::class)->execute())->toThrow(ResourceOperationException::class, 'Native Metrics activation awaits');
    expect(Operation::query()->count())->toBe($before);
});
