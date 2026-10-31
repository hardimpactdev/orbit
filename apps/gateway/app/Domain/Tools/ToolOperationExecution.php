<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Binds admitted commands and projects inventory. Swamp owns execution policy. */
final readonly class ToolOperationExecution implements OperationFamilyExecution
{
    /** @return array{descriptors: list<array{key: string, step: string, transport: string, name: string, input: array<string, mixed>, resumable: bool, required: bool}>} */
    public function context(Operation $operation): array
    {
        $input = $operation->input;
        $manager = ToolManagerName::from(ValidatedData::string($input['manager']))->value;
        $verb = substr($operation->kind, 5);
        $prefix = match ($manager) {
            'apt' => 'tool.apt-', 'composer' => 'tool.composer-', 'vp' => 'tool.vp-',
            'brew' => 'tool.homebrew-formula-', 'brew-cask' => 'tool.homebrew-cask-',
        };
        $version = in_array($manager, ['brew', 'brew-cask'], true) ? 'tool.homebrew-version' : $prefix.'version';
        $descriptors = [];
        $add = static function (string $key, string $transport, string $name, array $input, bool $required = false) use (&$descriptors): void {
            $descriptors[] = ['key' => $key, 'step' => $key, 'transport' => $transport, 'name' => $name,
                'input' => $input, 'resumable' => true, 'required' => $required];
        };
        if (isset($input['materialize_command']) && $verb === 'manager_converge') {
            $add('manager-materialize', 'agent', ValidatedData::string($input['materialize_command']), array_intersect_key($input, array_flip(ValidatedData::stringList($input['materialize_fields']))), true);
        }
        $add('manager-ready', 'agent', $version, [], true);
        $add('tool-verified', 'agent', $version, [], true);

        return ['descriptors' => $descriptors];
    }

    /** @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public function input(Operation $operation, array $run): array
    {
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $run['key'] && $descriptor['name'] === $run['name'] && $descriptor['transport'] === $run['transport']) {
                return $descriptor['input'];
            }
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The command does not belong to this Tool Operation.', 422);
    }

    /** @param array<string, mixed> $run */
    public function beforeRun(Operation $operation, array $run): void {}

    /** @param array<string, mixed> $run
     * @param  array<string, mixed>  $receipt
     * @return array<string, mixed>
     */
    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    /** @return array{key: string, transport: string, name: string, result: array<string, mixed>}|null */
    public function cancellationBoundary(Operation $operation): ?array
    {
        $key = 'manager-materialize';
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            if ($descriptor['key'] === $key) {
                return ['key' => $key, 'transport' => $descriptor['transport'], 'name' => $descriptor['name'], 'result' => []];
            }
        }

        return null;
    }

    /** Execution policy is checked once by the trusted Swamp workflow. */
    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}
}
