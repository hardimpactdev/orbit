<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\OperationStep;
use App\Support\ValidatedData;
use Illuminate\Support\Carbon;
use LogicException;

/** Fixed family declarations and intent/result projection. Swamp owns execution. */
final readonly class DirectOperation
{
    /** @var array<string, array{workflow: string, writer_config: string, writer_state: string, projection: class-string<DirectOperationProjection>, primitive?: class-string<DirectOperationPrimitive>}> */
    private array $definitions;

    public function __construct()
    {
        $definitions = [];
        foreach (glob(__DIR__.'/../*/direct-operations.php') ?: [] as $file) {
            foreach (require $file as $kind => $definition) {
                if (isset($definitions[$kind]) || preg_match(OperationKinds::NamePattern, $kind) !== 1
                    || array_diff(array_keys($definition), ['workflow', 'writer_config', 'writer_state', 'projection', 'primitive']) !== []
                    || array_diff(['workflow', 'writer_config', 'writer_state', 'projection'], array_keys($definition)) !== []
                    || array_any(array_intersect_key($definition, array_flip(['workflow', 'writer_config', 'writer_state'])), static fn (mixed $value): bool => ! is_string($value) || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $value) !== 1)
                    || ! is_a($definition['projection'], DirectOperationProjection::class, true)
                    || (isset($definition['primitive']) && ! is_a($definition['primitive'], DirectOperationPrimitive::class, true))) {
                    throw new LogicException('A direct Operation needs one fixed family declaration.');
                }
                $definitions[$kind] = $definition;
            }
        }
        $this->definitions = $definitions;
    }

    public static function owns(Operation $operation): bool
    {
        $definition = app(self::class)->definition($operation->kind);

        return $definition !== null && ($operation->state[$definition['writer_state']] ?? 'polled') === 'direct';
    }

    public function writer(string $kind): ?string
    {
        $definition = $this->definition($kind);
        if ($definition === null) {
            return null;
        }
        $writers = config($definition['writer_config'], []);
        $writer = is_array($writers) ? ($writers[$kind] ?? 'polled') : null;
        if (! in_array($writer, ['polled', 'direct'], true)) {
            throw new ResourceOperationException('operation.writer_invalid', 'Select polled or direct for the Swamp writer.', 503);
        }

        return $writer;
    }

    /** @return array<string, string> */
    public function stamp(string $kind, ?string $writer): array
    {
        $definition = $this->definition($kind);

        return $definition === null || $writer === null ? [] : [$definition['writer_state'] => $writer];
    }

    /** @return array{workflow: string, writer_config: string, writer_state: string, projection: class-string<DirectOperationProjection>, primitive?: class-string<DirectOperationPrimitive>}|null */
    public function definition(string $kind): ?array
    {
        return $this->definitions[$kind] ?? null;
    }

    public function assertRun(Operation $operation, string $runId): void
    {
        $known = $operation->state['swamp_run_id'] ?? null;
        if (! is_string($known) || $known === '') {
            throw new ResourceOperationException('operation.run_unbound', 'The Gateway has not recorded the native start response.', 409);
        }
        if ($known !== $runId) {
            throw new ResourceOperationException('operation.run_mismatch', 'This status belongs to another Swamp run.', 409);
        }
    }

    public function projection(Operation $operation): DirectOperationProjection
    {
        if (! self::owns($operation)) {
            throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
        }

        return app($this->definitions[$operation->kind]['projection']);
    }

    public function primitive(Operation $operation): DirectOperationPrimitive
    {
        $this->projection($operation);
        $primitive = $this->definitions[$operation->kind]['primitive'] ?? null;
        if ($primitive === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This intent declares no Gateway primitive.', 422);
        }

        return app($primitive);
    }

    /**
     * Project a family-validated, redacted result. No completion proof or execution decision.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $result
     */
    public function project(Operation $operation, array $data, array $result, string $stepName): Operation
    {
        $operation->refresh();
        if (! self::owns($operation)) {
            throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
        }
        $this->assertRun($operation, ValidatedData::string($data['run_id']));
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ($operation->status->terminal()) {
            if ($operation->status !== $status || ($operation->result ?? []) !== $result || $operation->error_code !== $code) {
                throw new ResourceOperationException('operation.completion_conflict', 'The Operation has another terminal result.', 409);
            }

            return $operation;
        }
        $step = $operation->steps()->where('name', $stepName)->first() ?? new OperationStep([
            'operation_id' => $operation->id, 'position' => 1, 'name' => $stepName, 'resumable' => true,
        ]);
        $startedAt = Carbon::parse(ValidatedData::string($data['started_at']));
        $finishedAt = $status->terminal() ? Carbon::parse(ValidatedData::string($data['finished_at'] ?? '')) : null;
        $state = $operation->state ?? [];
        if ($status === OperationStatus::Succeeded) {
            $state['steps'] = [...ValidatedData::object($state['steps'] ?? []), $stepName => $result];
        }
        app(OperationStore::class)->change($operation, [
            'status' => $status, 'started_at' => $operation->started_at ?? $startedAt, 'finished_at' => $finishedAt,
            'current_step' => $status->terminal() ? null : $stepName, 'failed_step' => $status === OperationStatus::Failed ? $stepName : null,
            'error_code' => $code, 'result' => $status->terminal() ? $result : null, 'state' => $state,
        ], $step, [
            'status' => OperationStepStatus::from($status->value), 'started_at' => $startedAt, 'finished_at' => $finishedAt, 'error_code' => $code,
        ]);

        return $operation->refresh();
    }
}
