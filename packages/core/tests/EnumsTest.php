<?php

declare(strict_types=1);

use Orbit\Core\Enums\ExecutionLane;
use Orbit\Core\Enums\OperationStatus;

describe(ExecutionLane::class, function (): void {
    it('exposes the documented lane cases and values', function (): void {
        expect(ExecutionLane::cases())->toHaveCount(3)
            ->and(array_map(
                static fn (ExecutionLane $lane): string => $lane->name,
                ExecutionLane::cases(),
            ))->toBe([
                'Host',
                'OrbitGateway',
                'LocalExecutor',
            ])
            ->and(array_map(
                static fn (ExecutionLane $lane): string => $lane->value,
                ExecutionLane::cases(),
            ))->toBe([
                'host',
                'orbit-gateway',
                'local-executor',
            ]);
    });

    it('serializes lanes to their wire values', function (): void {
        expect(json_encode([
            ExecutionLane::Host,
            ExecutionLane::OrbitGateway,
            ExecutionLane::LocalExecutor,
        ], JSON_THROW_ON_ERROR))->toBe('["host","orbit-gateway","local-executor"]')
            ->and(ExecutionLane::from('local-executor'))->toBe(ExecutionLane::LocalExecutor);
    });
});

describe(OperationStatus::class, function (): void {
    it('exposes the documented operation lifecycle cases and values', function (): void {
        expect(OperationStatus::cases())->toHaveCount(6)
            ->and(array_map(
                static fn (OperationStatus $status): string => $status->name,
                OperationStatus::cases(),
            ))->toBe([
                'Queued',
                'Running',
                'Succeeded',
                'Failed',
                'Expired',
                'Rejected',
            ])
            ->and(array_map(
                static fn (OperationStatus $status): string => $status->value,
                OperationStatus::cases(),
            ))->toBe([
                'queued',
                'running',
                'succeeded',
                'failed',
                'expired',
                'rejected',
            ]);
    });

    it('serializes operation statuses to their wire values', function (): void {
        expect(json_encode([
            OperationStatus::Queued,
            OperationStatus::Running,
            OperationStatus::Succeeded,
            OperationStatus::Failed,
            OperationStatus::Expired,
            OperationStatus::Rejected,
        ], JSON_THROW_ON_ERROR))->toBe('["queued","running","succeeded","failed","expired","rejected"]')
            ->and(OperationStatus::from('succeeded'))->toBe(OperationStatus::Succeeded);
    });

    it('reports whether an operation status is terminal', function (): void {
        expect(OperationStatus::Queued->isTerminal())->toBeFalse()
            ->and(OperationStatus::Running->isTerminal())->toBeFalse()
            ->and(OperationStatus::Succeeded->isTerminal())->toBeTrue()
            ->and(OperationStatus::Failed->isTerminal())->toBeTrue()
            ->and(OperationStatus::Expired->isTerminal())->toBeTrue()
            ->and(OperationStatus::Rejected->isTerminal())->toBeTrue();
    });
});
