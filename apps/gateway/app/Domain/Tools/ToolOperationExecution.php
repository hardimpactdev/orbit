<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Family-owned binding and receipt validation. Performs no Node work. */
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
        if (isset($input['materialize_command']) && in_array($verb, ['install', 'manager_converge'], true)) {
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
            if (in_array($verb, ['install', 'update'], true)) {
                $add('package-candidate', 'agent', $prefix.'candidate', $manager === 'composer' ? [...$package, 'operation' => $verb] : $bottle);
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
    public function beforeRun(Operation $operation, array $run): void
    {
        $this->input($operation, $run);
        if ($run['key'] !== 'package-mutate') {
            return;
        }
        $input = $operation->input;
        $steps = $this->proven($operation);
        $verb = substr($operation->kind, 5);
        if (! isset($steps['manager-ready']['version']) || ! array_key_exists('installed_version', $steps['package-before'] ?? [])) {
            $this->unproven();
        }
        $before = $steps['package-before']['installed_version'];
        if (($verb === 'install' && $input['tool_id'] === null && $before !== null) || ($verb === 'update' && $before === null)) {
            $this->unproven();
        }
        if ($verb !== 'remove' && is_string($before)) {
            $this->verifyVersion($operation, $before);
        }
        if ($verb !== 'remove' && $input['version_constraint'] !== null) {
            $candidate = $steps['package-candidate']['version'] ?? null;
            if (! is_string($candidate)) {
                $this->unproven();
            }
            $this->verifyVersion($operation, $candidate);
        }
        if ($verb === 'remove' && in_array($input['manager'], ['apt', 'vp', 'composer'], true)
            && ($steps['package-removal-plan']['packages'] ?? null) !== [$input['package']]) {
            $this->unproven();
        }
    }

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
        try {
            $inventory = new ToolInventoryReceipt()->parse($managerName, $receipt, $manager === null ? null : $manager->validatePackage(...));
            $version = $inventory->versionFor(ValidatedData::string($input['package']));
        } catch (ToolManagerException) {
            return ['error_code' => 'tool.version_probe_failed'];
        }
        $before = data_get($operation->state, 'executor_runs.package-before.result.installed_version');
        $outcome = match ($operation->kind) {
            'tool.remove' => 'applied',
            'tool.adopt' => ($input['tool_status'] ?? null) === 'installed' ? 'unchanged' : 'applied',
            'tool.install' => $before !== null ? 'unchanged' : 'applied',
            default => $before === $version ? 'unchanged' : 'applied',
        };
        if ($operation->kind === 'tool.update' && is_string(data_get($operation->state, 'executor_runs.package-candidate.result.version'))) {
            $candidate = ValidatedData::string(data_get($operation->state, 'executor_runs.package-candidate.result.version'));
            $constraint = ValidatedData::nullableString($input['version_constraint']);
            $normalized = $manager?->normalizeVersion($candidate);
            if ($constraint !== null && $normalized !== null && ! app(VersionConstraint::class)->allows($normalized, $constraint)) {
                $outcome = 'blocked_by_constraint';
            }
        }

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

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            return;
        }
        $input = $operation->input;
        $result = $outcome->result ?? [];
        $steps = $this->proven($operation);
        if (! is_string($steps['manager-ready']['version'] ?? null)) {
            $this->unproven();
        }
        if (isset($input['materialize_command']) && ! isset($steps['manager-materialize'])) {
            $this->unproven();
        }
        if ($operation->kind === 'tool.manager_converge') {
            if (! is_string($result['version'] ?? null)) {
                $this->unproven();
            }

            return;
        }
        if ($input['intent_only']) {
            return;
        }
        if (! array_key_exists('installed_version', $result)) {
            $this->unproven();
        }
        $after = $result['installed_version'];
        if ($operation->kind !== 'tool.adopt') {
            if (! array_key_exists('installed_version', $steps['package-before'] ?? [])) {
                $this->unproven();
            }
            $before = $steps['package-before']['installed_version'];
            $requiresMutation = match ($operation->kind) {
                'tool.install', 'tool.remove' => $before !== null ? $operation->kind === 'tool.remove' : $operation->kind === 'tool.install',
                default => true,
            };
            if ($operation->kind === 'tool.update' && ($result['outcome'] ?? null) === 'blocked_by_constraint') {
                $candidate = $steps['package-candidate']['version'] ?? null;
                $manager = app(ToolManagerRegistry::class)->find(ValidatedData::string($input['manager']));
                $normalized = is_string($candidate) ? $manager?->normalizeVersion($candidate) : null;
                $constraint = ValidatedData::nullableString($input['version_constraint']);
                if ($normalized === null || $constraint === null || app(VersionConstraint::class)->allows($normalized, $constraint) || $after !== $before) {
                    $this->unproven();
                }
                $requiresMutation = false;
            }
            if ($requiresMutation && ! isset($steps['package-mutate'])) {
                $this->unproven();
            }
        }
        if ($operation->kind === 'tool.remove') {
            if ($after !== null) {
                $this->unproven();
            }

            return;
        }
        if (! is_string($after) || $after === '' || strlen($after) > 255 || preg_match('/[\x00-\x1F\x7F]/', $after) === 1) {
            $this->unproven();
        }
        if ($operation->kind === 'tool.adopt' && $input['manager'] === 'composer'
            && ! in_array($input['package'], ValidatedData::stringList($steps['adoption-scope']['roots'] ?? []), true)) {
            $this->unproven();
        }
        if ($operation->kind === 'tool.adopt' && ($result['block'] ?? null) !== null) {
            $this->unproven();
        }
        if ($operation->kind === 'tool.install' && $input['tool_id'] === null
            && (! array_key_exists('installed_version', $steps['package-before'] ?? []) || $steps['package-before']['installed_version'] !== null)) {
            $this->unproven();
        }
        $this->verifyVersion($operation, $after);
    }

    /** @return array<string, array<string, mixed>> */
    private function proven(Operation $operation): array
    {
        $results = [];
        $runs = ValidatedData::object($operation->state['executor_runs'] ?? []);
        foreach ($this->context($operation)['descriptors'] as $descriptor) {
            $run = $runs[$descriptor['key']] ?? null;
            if (is_array($run) && ($run['transport'] ?? null) === $descriptor['transport'] && ($run['name'] ?? null) === $descriptor['name']
                && is_array($run['result'] ?? null) && ! isset($run['result']['error_code'])) {
                $results[$descriptor['key']] = ValidatedData::object($run['result']);
            }
        }

        return $results;
    }

    private function verifyVersion(Operation $operation, string $raw): void
    {
        $constraint = ValidatedData::nullableString($operation->input['version_constraint']);
        if ($constraint === null) {
            return;
        }
        $manager = app(ToolManagerRegistry::class)->find(ValidatedData::string($operation->input['manager']));
        $normalized = $manager?->normalizeVersion($raw);
        if ($normalized === null || ! app(VersionConstraint::class)->allows($normalized, $constraint)) {
            $this->unproven();
        }
    }

    private function unproven(): never
    {
        throw new ResourceOperationException('operation.completion_unproven', 'The Tool outcome requires proven package observations.', 409);
    }
}
