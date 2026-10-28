<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Integrations\IntegrationExecution;
use App\Domain\Integrations\IntegrationIntent;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class AiRouterExecution implements OperationFamilyExecution, OperationFamilyChildren
{
    public function __construct(private IntegrationExecution $effects) {}

    public function context(Operation $operation): array
    {
        if ($operation->input !== []) {
            return $this->effects->context($operation);
        }
        $prepare = ['key' => 'collector-prepare', 'step' => 'collector-prepare', 'transport' => 'gateway', 'name' => 'ai_router.prepare', 'input' => [], 'resumable' => true, 'required' => true];

        return ['descriptors' => [$prepare, ...($this->prepared($operation) ? $this->effects->context($operation)['descriptors'] : [])]];
    }

    /** @return array{intent: array<string, mixed>, children: list<array{key: string, kind: string, node_id: int, input: array<string, mixed>}>} */
    public function childContext(Operation $operation): array
    {
        if ($operation->input === [] && ! $this->prepared($operation)) { return ['intent' => [], 'children' => []]; }
        return $this->effects->childContext($operation);
    }

    public function input(Operation $operation, array $run): array
    {
        return $run['key'] === 'collector-prepare' ? [] : $this->effects->input($operation, $run);
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        if (in_array($run['key'], ['collector-prepare', 'collector-takeover-restore'], true)) {
            return;
        }
        if ($operation->input === [] && ! $this->prepared($operation)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The collector intent is unprepared.', 409);
        }
        $this->effects->beforeRun($operation, $run);
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($operation->input === [] && $outcome->status === OperationStatus::Succeeded && ! $this->prepared($operation)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The collector preparation has no proven receipt.', 409);
        }
        if ($outcome->status === OperationStatus::Failed && isset(IntegrationIntent::data($operation)['failure_recovery'])) {
            try { $publication = app(OperationChildren::class)->child($operation, 'collector-publication'); }
            catch (ResourceOperationException) { $publication = null; }
            if ($publication?->status !== OperationStatus::Succeeded) {
                $run = ValidatedData::object($operation->state['executor_runs'] ?? [])['collector-takeover-restore'] ?? null;
                $restored = is_array($run) && is_array($run['result'] ?? null) && ($run['result']['restored'] ?? false) === true;
                $republished = app(OperationChildren::class)->requireSucceeded($operation, 'collector-takeover-republish');
                if ($restored !== true || $republished?->status !== OperationStatus::Succeeded) {
                    throw new ResourceOperationException('operation.completion_unproven', 'The refused collector takeover has not been restored and republished.', 409);
                }
            }
        }
        $this->effects->assertCompletion($operation, $outcome);
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    private function prepared(Operation $operation): bool
    {
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $run = $runs['collector-prepare'] ?? null;

        return is_array($run) && ($run['name'] ?? null) === 'ai_router.prepare' && ($run['transport'] ?? null) === 'gateway'
            && ($run['result'] ?? null) === ['prepared' => true];
    }
}
