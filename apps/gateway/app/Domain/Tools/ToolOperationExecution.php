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
        if ($verb === 'manager_converge') {
            $add('tool-verified', 'agent', $version, [], true);

            return ['descriptors' => $descriptors];
        }
        $observation = [];
        $inventory = $prefix.'inventory';
        if (! ($input['intent_only'] ?? false) && $verb !== 'adopt') {
            $add('package-before', 'agent', $inventory, $observation);
            $package = ['package' => $input['package']];
            $bottle = $package;
            if ($input['platform'] === 'macos' && in_array($manager, ['brew', 'brew-cask'], true)) {
                $bottle['architecture'] = $input['architecture'];
            }
            if ($verb === 'remove' && in_array($manager, ['apt', 'vp', 'composer'], true)) {
                $add('package-removal-plan', 'agent', $prefix.'removal-plan', $package);
            }
            $add('package-mutate', 'agent', $prefix.$verb, $manager === 'brew' && $verb === 'remove' ? $package : $bottle);
        }
        if ($input['intent_only'] ?? false) {
            $add('tool-verified', 'agent', $version, [], true);
        } elseif ($verb === 'adopt' && in_array($manager, ['brew', 'brew-cask'], true)) {
            $adoptionInput = ['package' => $input['package']];
            if ($input['platform'] === 'macos') {
                $adoptionInput['architecture'] = $input['architecture'];
            }
            $add('tool-verified', 'agent', $prefix.'adoption', $adoptionInput, true);
        } else {
            if ($verb === 'adopt' && $manager === 'composer') {
                $add('adoption-scope', 'agent', 'tool.inspect-composer-scope', []);
            }
            $add('tool-verified', 'agent', $inventory, [], true);
        }

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
        if (str_ends_with(ValidatedData::string($run['name']), '-adoption') && ! isset($receipt['error_code'])) {
            return [...$receipt, 'outcome' => ($operation->input['tool_status'] ?? null) === 'installed' ? 'unchanged' : 'applied'];
        }
        if (isset($receipt['error_code']) || ! str_ends_with(ValidatedData::string($run['name']), '-inventory')) {
            return $receipt;
        }
        $input = $operation->input;
        $managerName = ToolManagerName::from(ValidatedData::string($input['manager']));
        $manager = app(ToolManagerRegistry::class)->find($managerName->value);
        $validatePackage = null;
        if ($manager !== null) {
            $validatePackage = $manager->validatePackage(...);
        }
        try {
            $inventory = new ToolInventoryReceipt()->parse($managerName, $receipt, $validatePackage);
            $version = $inventory->versionFor(ValidatedData::string($input['package']));
        } catch (ToolManagerException) {
            return ['error_code' => 'tool.version_probe_failed'];
        }
        $before = data_get($operation->state, 'executor_runs.package-before.result.installed_version');
        $outcome = match ($operation->kind) {
            'tool.remove' => 'applied',
            'tool.adopt' => ($input['tool_status'] ?? null) === 'installed' ? 'unchanged' : 'applied',
            default => $before === $version ? 'unchanged' : 'applied',
        };

        return ['installed_version' => $version, 'block' => null, 'outcome' => $outcome];
    }

    /** @return array{key: string, transport: string, name: string, result: array<string, mixed>}|null */
    public function cancellationBoundary(Operation $operation): ?array
    {
        $key = $operation->kind === 'tool.manager_converge' ? 'manager-materialize' : 'package-mutate';
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
