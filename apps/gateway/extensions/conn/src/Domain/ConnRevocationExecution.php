<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Exposes exact owner-bound effects and accepts completion only after every keyed step is proven. */
final readonly class ConnRevocationExecution implements OperationFamilyExecution
{
    public const array ResultFields = ['backend', 'revoked', 'revoked_sessions', 'error_code',
        ['name' => 'sessions', 'type' => 'list', 'items' => 'string', 'max' => 4096],
        ['name' => 'links', 'type' => 'list', 'items' => 'string', 'max' => 4096]];

    public function context(Operation $operation): array
    {
        $descriptors = [$this->descriptor($operation, 'revocation-inspect', 'inspect', '')];
        $inspection = $this->proven($operation, 'revocation-inspect');
        if (($inspection['backend'] ?? null) !== 'inspected') {
            return ['descriptors' => $descriptors];
        }
        $settled = true;
        foreach (['sessions' => 'session', 'links' => 'link'] as $list => $action) {
            foreach (ValidatedData::stringList($inspection[$list]) as $index => $id) {
                $key = $action.'-'.$index;
                $descriptors[] = $this->descriptor($operation, $key, $action, $id);
                $settled = $settled && ($this->proven($operation, $key)['backend'] ?? null) === 'revoked';
            }
        }
        if ($settled) {
            $descriptors[] = $this->descriptor($operation, $this->finalStep($operation), 'receipt', '');
        }

        return ['descriptors' => $descriptors];
    }

    public function input(Operation $operation, array $run): array
    {
        return array_find($this->context($operation)['descriptors'], static fn (array $descriptor): bool => $descriptor['key'] === $run['key'])['input'] ?? [];
    }

    public function beforeRun(Operation $operation, array $run): void {}

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded && $outcome->result !== $this->result($operation)) {
            throw new ResourceOperationException('operation.completion_unproven', 'Every admitted Conn revocation must be proven.', 409);
        }
    }

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }

    /** @return array{backend:string,revoked_sessions:int} */
    public function result(Operation $operation): array
    {
        $inspection = $this->proven($operation, 'revocation-inspect');
        if (($inspection['backend'] ?? null) !== 'inspected') {
            throw new ResourceOperationException('operation.completion_unproven', 'The Conn inspection must be proven.', 409);
        }
        $count = 0;
        foreach (['sessions' => 'session', 'links' => 'link'] as $list => $action) {
            foreach (ValidatedData::stringList($inspection[$list]) as $index => $id) {
                $proof = $this->proven($operation, $action.'-'.$index);
                if (($proof['backend'] ?? null) !== 'revoked' || ! is_bool($proof['revoked'] ?? null)) {
                    throw new ResourceOperationException('operation.completion_unproven', 'Every admitted Conn revocation must be proven.', 409);
                }
                $count += $action === 'session' && $proof['revoked'] ? 1 : 0;
            }
        }

        return ['backend' => 'revoked', 'revoked_sessions' => $count];
    }

    public function finalStep(Operation $operation): string
    {
        return 'gateway-sessions-revoke';
    }

    /** @return array<string,mixed>|null */
    private function proven(Operation $operation, string $key): ?array
    {
        $state = ValidatedData::object($operation->state);
        $runs = ValidatedData::object($state['executor_runs'] ?? []);
        $receipt = ValidatedData::object($runs[$key] ?? []);
        $steps = ValidatedData::object($state['steps'] ?? []);
        $result = $receipt['result'] ?? null;
        if (! is_array($result) || isset($result['error_code']) || ($receipt['name'] ?? null) !== 'conn.revocation'
            || ($receipt['transport'] ?? null) !== 'gateway' || ($receipt['step'] ?? null) !== $key
            || ($steps[$key] ?? null) !== $result) {
            return null;
        }

        return ValidatedData::object($result);
    }

    /** @return array{key:string,step:string,transport:string,name:string,input:array<string,mixed>,resumable:bool,required:bool} */
    private function descriptor(Operation $operation, string $key, string $action, string $id): array
    {
        return ['key' => $key, 'step' => $key, 'transport' => 'gateway', 'name' => 'conn.revocation',
            'input' => ['environment' => $operation->input['environment'], 'action' => $action, 'id' => $id], 'resumable' => true, 'required' => true];
    }
}
