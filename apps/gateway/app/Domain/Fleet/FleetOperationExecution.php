<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** Fleet input descriptors and live window checks; Swamp owns the order and retries. */
final readonly class FleetOperationExecution implements OperationFamilyChildren, OperationFamilyExecution
{
    private const array Steps = [
        'fleet.recipe-begin' => 'recipe-begin', 'recipe.incus-preflight' => 'incus-preflight',
        'recipe.apply' => 'recipe-apply', 'php.install-build' => 'php-build',
        'recipe.verify-delivery' => 'recipe-delivery', 'fleet.verify' => 'verify',
        'fleet.probe' => 'reachability', 'cli.install' => 'cli-install', 'cli.self-update' => 'self-update',
    ];

    public function __construct(private OperationKinds $kinds, private FleetWindow $window, private FleetFootprintChild $children) {}

    public function context(Operation $operation): array
    {
        $kind = $this->kinds->definition($operation->kind);
        if (! is_array($kind) || ! isset($kind['runs'])) {
            throw new \LogicException('Fleet execution needs a declared kind.');
        }
        $descriptors = [];
        foreach ($kind['runs'] as $run) {
            if (($run['name'] === 'recipe.incus-preflight' && ($operation->input['automatic'] ?? false))
                || ($run['name'] === 'php.install-build' && ! ($operation->input['has_php'] ?? false))) {
                continue;
            }
            $step = self::Steps[$run['name']];
            $key = $step;
            if ($run['name'] === 'cli.self-update' && in_array('downgrade_to', $run['input_fields'] ?? [], true)) {
                if (($operation->input['downgrade_to'] ?? null) === null) {
                    continue;
                }
                $key .= '-downgrade';
            }
            $input = [...Arr::only($operation->input, $run['input_fields'] ?? []), ...($run['input_values'] ?? [])];
            $descriptor = ['key' => $key, 'step' => $step, 'transport' => $run['transport'], 'name' => $run['name'],
                'input' => $input, 'resumable' => $run['resumable'] ?? false, 'required' => $run['name'] !== 'fleet.probe' && $key !== 'self-update-downgrade'];
            $descriptors[] = $descriptor;
            if (in_array($run['name'], ['cli.install', 'cli.self-update'], true)) {
                $descriptors[] = [...$descriptor, 'key' => $key.'-retry', 'required' => false];
            }
        }

        $probe = array_find($descriptors, static fn (array $entry): bool => $entry['name'] === 'fleet.probe');
        if ($probe !== null) {
            foreach ($descriptors as $descriptor) {
                if (in_array($descriptor['name'], ['cli.install', 'cli.self-update'], true)) {
                    $descriptors[] = [...$probe, 'key' => $descriptor['key'].'-probe'];
                }
            }
        }

        return ['descriptors' => $descriptors, 'result' => ['wait_resume' => $this->children->waitResume($operation)]];
    }

    public function childContext(Operation $parent): array
    {
        $recipe = $parent->kind === 'fleet.recipe';
        $needed = ! $recipe ? $this->children->cliReady($parent) : (($parent->input['has_php'] ?? false) && ($parent->input['vpn'] ?? false)
            && Arr::get($parent->state ?? [], 'steps.php-build.outcome') === 'applied');

        return ['intent' => [], 'children' => $needed ? [[
            'key' => 'footprint', 'kind' => 'node.converge', 'node_id' => $parent->node_id,
            'input' => ['force_artifacts' => $recipe || ($parent->input['repairs'] ?? []) !== [],
                'first_visit' => ! $recipe && ($parent->input['first_visit'] ?? false) === true],
        ]] : []];
    }

    public function input(Operation $operation, array $run): array
    {
        $descriptor = array_find($this->context($operation)['descriptors'], static fn (array $entry): bool => $entry['key'] === $run['key']
            && $entry['transport'] === $run['transport'] && $entry['name'] === $run['name']);
        if ($descriptor === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The fleet invocation has no admitted descriptor.', 422);
        }

        return $descriptor['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $this->assertWindow($operation);
    }

    private function assertWindow(Operation $operation): void
    {
        if ($operation->kind !== 'fleet.rollout' && ! ($operation->input['automatic'] ?? false)) {
            return;
        }
        $node = Node::query()->with('roles')->findOrFail($operation->node_id);
        if (! $this->window->allows($node)) {
            throw new ResourceOperationException('fleet.window_closed', 'Fleet effects on this Node require 03:00–06:00 Europe/Amsterdam.', 409);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $run['name'] === 'cli.self-update'
            ? [...$receipt, 'report_present' => is_array($receipt['report'] ?? null)]
            : $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
