<?php

declare(strict_types=1);

namespace App\Domain\Instances\VpEnvironment;

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceExecutionEvidence;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\VpEnvironment;
use App\Domain\Tools\VpProjectRequirements;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Reads Project runtime requirements in memory and stores public checkout references only. */
final readonly class InstanceVpEnvironmentMaterial
{
    public function __construct(private AgentCommandRunner $agents) {}

    public static function required(string $name, string $command): bool
    {
        return $name === 'npm-build' || preg_match('/(?:^|[\s;&|\/])(vp|node|npm|npx|pnpm|yarn|bun|vite|corepack)(?:\s|$)/', $command) === 1;
    }

    /** @return array{Instance, Operation, string} */
    public function target(Operation $operation): array
    {
        $parentId = $operation->getAttribute('parent_operation_id');
        $parent = is_int($parentId) ? Operation::query()->find($parentId) : null;
        $instance = Instance::query()->with(['node', 'project'])->findOrFail($operation->target_id);
        if ($operation->kind !== 'instance.prepare_vp_environment' || $operation->target_type !== 'instance'
            || $operation->node_id !== $instance->node_id || ($operation->input['instance_id'] ?? null) !== $instance->id
            || ! in_array($parent?->kind, ['instance.create', 'instance.deploy'], true) || $parent->target_type !== 'instance'
            || $parent->target_id !== $instance->id || $parent->node_id !== $operation->node_id
            || ($parent->input['instance_id'] ?? null) !== $instance->id) {
            throw $this->invalid();
        }
        if ($parent->kind === 'instance.create') {
            return [$instance, $parent, $instance->checkout_path];
        }
        $intent = InstanceDeploymentIntent::admitted($parent);
        $path = $intent['variant'] === 'development' ? $intent['home']
            : (InstanceDeploymentIntent::storedRelease(InstanceExecutionEvidence::step($parent, 'source_preparation')['release'] ?? null)?->path);
        $sources = $intent['variant'] === 'development'
            ? [['key' => 'source.checkout', 'name' => 'instance.checkout-development-default']]
            : [['key' => 'source.prepare', 'name' => 'instance.prepare-release']];
        $proven = array_any($sources, static function (array $source) use ($parent): bool {
            $run = InstanceExecutionEvidence::run($parent, $source['key']);

            return ($run['name'] ?? null) === $source['name'] && ($run['transport'] ?? null) === 'instance-deploy-agent'
                && ($run['step'] ?? null) === 'source_preparation' && is_array($run['result'] ?? null) && ! isset($run['result']['error_code'])
                && InstanceExecutionEvidence::step($parent, 'source_preparation') === $run['result']
                && $parent->steps()->where('name', 'source_preparation')->where('status', 'succeeded')->exists();
        });
        if (! is_string($path) || $intent['project_id'] !== $instance->project_id
            || $intent['repository'] !== $instance->project->repository_url
            || ! $proven) {
            throw $this->invalid();
        }

        return [$instance, $parent, $path];
    }

    /** @return array{path:string,requirements:array<string,string|null>|null} */
    public function plan(Operation $operation): array
    {
        [$instance, $parent, $path] = $this->target($operation);
        if ($parent->kind === 'instance.deploy' && ! InstanceDeploymentIntent::admitted($parent)['needs_vp']) {
            return ['path' => $path, 'requirements' => null];
        }
        $environment = $this->requirements($instance, $path);

        return ['path' => $path, 'requirements' => $environment?->record('checking')];
    }

    public function npmrc(Operation $operation): string
    {
        [$instance, , $path] = $this->target($operation);
        $configuration = $this->read($instance, $path);
        $environment = new VpProjectRequirements()->resolve($configuration, '/unresolved');
        $admitted = ValidatedData::object($operation->state['vp_plan'] ?? [])['requirements'] ?? null;
        if ($environment?->record('checking') !== $admitted) {
            throw $this->invalid();
        }

        return is_string($configuration['npmrc'] ?? null) ? $configuration['npmrc'] : '';
    }

    private function requirements(Instance $instance, string $path): ?VpEnvironment
    {
        try {
            return new VpProjectRequirements()->resolve($this->read($instance, $path), '/unresolved');
        } catch (AgentCommandRefused $failure) {
            if ($failure->errorCode !== 'tool.project_invalid') {
                throw $failure;
            }
            throw new ResourceOperationException('deployment.vp_requirements_invalid', 'The Project runtime manifest is invalid.', 422);
        } catch (InvalidArgumentException) {
            throw new ResourceOperationException('deployment.vp_requirements_invalid', 'Pin exact Node and package-manager versions in a compatible Vite+ lockfile.', 422);
        }
    }

    /** @return array<string,mixed> */
    private function read(Instance $instance, string $path): array
    {
        return $this->agents->run($instance->node, 'tool.read-vp-project', ['path' => $path,
            'user' => $instance->placedOnAppProd() ? $instance->production_user : $instance->node->user]);
    }

    public function reference(Operation $operation, VpEnvironment $environment, string $status): void
    {
        [$instance, , $path] = $this->target($operation);
        DB::transaction(function () use ($instance, $path, $environment, $status): void {
            DB::table('nodes')->where('id', $instance->node_id)->update(['id' => $instance->node_id]);
            $previous = $this->checkoutReferences($instance->refresh()->getAttribute('vp_environment'), $instance->checkout_path);
            $identity = $environment->record('checking');
            unset($identity['status']);
            $previous = $this->filter($previous, static function (array $record) use ($path, $identity): bool {
                foreach ($identity as $key => $value) {
                    if (($record[$key] ?? null) !== $value) {
                        return true;
                    }
                }

                return ($record['checkout_path'] ?? null) !== $path;
            });
            $instance->update(['vp_environment' => [...$environment->record($status), 'checkout_path' => $path, 'previous' => $previous]]);
        });
    }

    /** @return list<string> */
    public function stores(Instance $instance): array
    {
        $stores = [];
        for ($record = $instance->getAttribute('vp_environment'); is_array($record); $record = $record['previous'] ?? null) {
            if (is_string($record['store'] ?? null)) {
                $stores[] = $record['store'];
            }
        }

        return array_values(array_unique($stores));
    }

    /** @return list<array<string,?string>> */
    public function references(int $nodeId, string $store): array
    {
        $references = [];
        foreach (Instance::query()->where('node_id', $nodeId)->orderBy('id')->get() as $instance) {
            for ($record = $instance->getAttribute('vp_environment'); is_array($record); $record = $record['previous'] ?? null) {
                if (($record['store'] ?? null) === $store) {
                    $references[] = VpEnvironment::fromArray($record)->input();
                }
            }
        }

        return $references;
    }

    /** @param list<string> $paths */
    public function retire(Instance $instance, array $paths): void
    {
        DB::transaction(function () use ($instance, $paths): void {
            DB::table('nodes')->where('id', $instance->node_id)->update(['id' => $instance->node_id]);
            $record = $this->checkoutReferences($instance->refresh()->getAttribute('vp_environment'), $instance->checkout_path);
            $instance->update(['vp_environment' => $this->filter($record, static fn (array $reference): bool => ($reference['checkout_path'] ?? null) === $instance->checkout_path
                || ! in_array($reference['checkout_path'] ?? null, $paths, true))]);
        });
    }

    /** @return array<string,mixed>|null */
    private function checkoutReferences(mixed $record, string $selectedPath): ?array
    {
        return is_array($record) ? [...$record, 'checkout_path' => $record['checkout_path'] ?? $selectedPath,
            'previous' => $this->checkoutReferences($record['previous'] ?? null, $selectedPath)] : null;
    }

    /** @return array<string,mixed>|null */
    private function filter(mixed $record, \Closure $keep): ?array
    {
        if (! is_array($record)) {
            return null;
        }
        $previous = $this->filter($record['previous'] ?? null, $keep);

        return $keep($record) ? [...$record, 'previous' => $previous] : $previous;
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The Vite+ environment does not match this deployment checkout.', 422);
    }
}
