<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\NodeCliInstallation;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Proven CLI result and Gateway state projection. Performs no Node effect. */
final readonly class CliConvergeResult implements OperationCompletion, OperationGatewayCommand
{
    public function __construct(private NodeCliState $state) {}

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (($operation->input['skip_reason'] ?? null) !== null) {
            return ['completed' => true, 'outcome' => 'skipped', 'reason' => $operation->input['skip_reason'], 'version' => null, 'configured' => false];
        }
        if (($operation->input['plan_error'] ?? null) !== null) {
            return ['error_code' => $operation->input['plan_error']];
        }
        $result = $this->installReceipt($operation);
        $steps = ValidatedData::object($operation->state['steps'] ?? []);
        if (($steps['cli-install'] ?? null) !== $result || isset($result['error_code'])
            || ! array_key_exists('installed', $result) || ! is_bool($result['configured'] ?? null)
            || ($result['installed'] !== null && $result['installed'] !== Arr::get($operation->input, 'release.version'))) {
            $this->unproven();
        }

        return ['completed' => true, 'outcome' => is_string($result['installed']) ? NodeCliInstallation::Installed : NodeCliInstallation::Present,
            'reason' => null, 'version' => $result['installed'], 'configured' => $result['configured']];
    }

    public function assertOutcome(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded) {
            $expected = $this->run($operation, []);
            if (($expected['completed'] ?? false) !== true || Arr::sortRecursive($outcome->result ?? []) !== Arr::sortRecursive($expected)) {
                $this->unproven();
            }
        }
        if ($outcome->errorCode === 'cli.foreign_binary' && ($this->installReceipt($operation)['error_code'] ?? null) !== 'cli.foreign_binary') {
            $this->unproven();
        }
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $this->assertOutcome($operation, $outcome);
        $node = Node::query()->findOrFail($operation->node_id);
        if ($outcome->errorCode === 'cli.foreign_binary') {
            $this->state->markForeign($node);
        } elseif ($outcome->status === OperationStatus::Succeeded && ($outcome->result['outcome'] ?? null) !== 'skipped') {
            $this->state->clear($node);
        }
    }

    /** @return array<string, mixed> */
    private function installReceipt(Operation $operation): array
    {
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        $receipt = ValidatedData::object($runs['cli-install'] ?? []);
        $input = $receipt['input'] ?? null;
        if (($receipt['transport'] ?? null) !== 'agent' || ($receipt['name'] ?? null) !== 'cli.install' || ! is_array($input)
            || Arr::sortRecursive($input) !== Arr::sortRecursive(Arr::only($operation->input, ['user', 'release', 'profile']))
            || ! is_array($receipt['result'] ?? null)) {
            $this->unproven();
        }

        return ValidatedData::object($receipt['result']);
    }

    private function unproven(): never
    {
        throw new ResourceOperationException('operation.completion_unproven', 'CLI completion requires the exact admitted install receipt.', 409);
    }
}
