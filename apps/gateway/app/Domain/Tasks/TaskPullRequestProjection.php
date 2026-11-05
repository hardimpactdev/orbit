<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records the receipt or the error code of a native pull request step. Swamp owns the GitHub calls and their policy. */
abstract readonly class TaskPullRequestProjection implements DirectOperationProjection, NativeOperationProjection
{
    /**
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed> the bounded result of a succeeded step, from its decoded receipt
     */
    abstract public function succeeded(Operation $operation, array $receipt): array;

    /** @return list<string> error codes that a failed step names in its error text */
    abstract protected function codes(): array;

    /** @return array<string, string> the error code of each step that fails with a GitHub reply */
    abstract protected function stepCodes(): array;

    abstract protected function fallback(): string;

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => array_diff_key($operation->input, ['execution_secrets' => true]), 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        if ($status === OperationStatus::Succeeded) {
            $native = ValidatedData::object($data['result'] ?? []);
            $receipt = ValidatedData::object(json_decode(ValidatedData::string($native['pr_receipt']), true, flags: JSON_THROW_ON_ERROR));
            $result = $this->succeeded($operation, $receipt);
        } elseif (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)) {
            $code = $this->failureCode(ValidatedData::object($data['failure'] ?? []));
            $data['error_code'] = $code;
            $result = ['error_code' => $code];
        } else {
            $result = [];
        }
        $data['result'] = $result;

        return app(DirectOperation::class)->project($operation, $data, $result);
    }

    /**
     * The error text of the failed step when it names a known code, else the code of the step that failed.
     *
     * @param  array<string, mixed>  $failure
     */
    public function failureCode(array $failure): string
    {
        $detail = $failure['detail'] ?? null;
        $step = $failure['failed_step'] ?? null;

        return is_string($detail) && in_array($detail, $this->codes(), true) ? $detail : ($this->stepCodes()[is_string($step) ? $step : ''] ?? $this->fallback());
    }

    /** @param array<string, mixed> $receipt */
    protected function requireHead(Operation $operation, array $receipt, string $key): void
    {
        if (($receipt[$key] ?? null) !== $operation->input['head_sha']) {
            throw new ResourceOperationException('operation.invalid_result', 'The native receipt does not name the admitted head.', 422);
        }
    }
}
