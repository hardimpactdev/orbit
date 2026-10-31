<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationEffectTransport;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentEffectReceipts;
use App\Infrastructure\AgentCommands\AgentSchemas;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;

/** Fixed host effects on the current attempt's builder, smoke VM, candidate image and topology seed. */
final readonly class TaskVmImageHostCommand implements OperationEffectTransport
{
    use TaskVmTargetReceipt;
    use TaskVmTransportSchema;

    public function __construct(private AgentCommandRunner $agents, private IncusHost $incus, private TaskVmSettings $settings,
        private RecipeImageBuildSteps $eligibility, private RecipeImagePruning $pruning, private SshKeyProvider $keys) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $build = TaskVmImageBuild::query()->findOrFail($operation->target_id);
        $host = $this->settings->host($build->host_node_id);
        if ($operation->target_type !== 'task_vm_image' || $operation->node_id !== $build->host_node_id
            || ($operation->input['attempt'] ?? null) !== $build->attempt || $build->cleanup_started_at !== null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The image attempt does not own this effect.', 422);
        }
        $this->eligibility->assertCurrent($build);
        if ($operation->kind === 'task_vm.image_promote') {
            $this->eligibility->assertPromotion($build);
            $this->agents->run($build->hostNode, 'incus.image', AgentEffectReceipts::input($run, ['project' => $host->project, 'operation' => ['kind' => 'promote', 'alias' => $host->imageAlias, 'fingerprint' => $build->image_fingerprint]]));

            return ['ready' => true, 'fingerprint' => $build->image_fingerprint];
        }
        if ($operation->kind === 'task_vm.image_prune') {
            if ($build->promoted_at === null) {
                throw new ResourceOperationException('operation.completion_unproven', 'The image must be promoted before pruning.', 409);
            }
            $this->agents->run($build->hostNode, 'incus.image', AgentEffectReceipts::input($run, $this->pruning->input($build)));

            return ['ready' => true];
        }
        $command = ValidatedData::string($input['command']);
        unset($input['command']);
        AgentSchemas::assertInput($command, $input);
        $name = (string) $build->builder_vm_name;
        $fingerprint = $build->images['base']['fingerprint'] ?? null;
        $valid = false;
        if ($command === 'incus.vm') {
            $location = ValidatedData::object($input['vm']);
            $effect = ValidatedData::object($input['operation']);
            $expected = $operation->kind === 'task_vm.image_launch' ? 'create_image' : 'remove_instance';
            $valid = ($location['project'] ?? null) === $host->project && ($location['cidr'] ?? null) === $host->cidr
                && in_array($location['name'] ?? null, [$name, $name.'-smoke'], true) && ($effect['kind'] ?? null) === $expected;
            if ($valid && $expected === 'create_image') {
                $valid = ($effect['network'] ?? null) === $host->network
                    && ($effect['cpus'] ?? null) === $host->cpus && ($effect['memory'] ?? null) === $host->memory && ($effect['disk'] ?? null) === $host->disk
                    && ($effect['gateway_public_key'] ?? null) === $this->keys->publicKey() && ($effect['builder'] ?? null) === (($location['name'] ?? null) === $name);
                if (($location['name'] ?? null) === $name && $build->source_alias === null) {
                    $build->forceFill(['source_alias' => TaskVmHost::SourceImage, 'source_fingerprint' => $this->incus->aliasTarget($host, $build->hostNode, TaskVmHost::SourceImage)])->save();
                }
                $expectedImage = ($location['name'] ?? null) === $name ? ($build->source_fingerprint ?? TaskVmHost::SourceImage) : $fingerprint;
                if (($location['name'] ?? null) === $name && $effect['image'] === TaskVmHost::SourceImage) {
                    $effect['image'] = $expectedImage;
                    $input['operation'] = $effect;
                } else {
                    $valid = $valid && $effect['image'] === $expectedImage;
                }
            }
        } elseif ($command === 'incus.image') {
            $effect = ValidatedData::object($input['operation']);
            $expected = $operation->kind === 'task_vm.image_publish' ? 'publish' : 'prepare_metadata';
            $valid = $input['project'] === $host->project && $effect['kind'] === $expected && ($effect['name'] ?? null) === $name;
            if ($expected === 'publish') {
                $alias = $build->images['base']['alias'] ?? RecipeImageBuilds::alias($host->imageAlias, (string) $build->recipe_identity, 'base', $build->id);
                $valid = $valid && ($effect['alias'] ?? null) === $alias;
                $properties = ValidatedData::object($effect['properties'] ?? []);
                $valid = $valid && ($properties['orbit.recipe'] ?? null) === $build->recipe_identity && ($properties['orbit.profile'] ?? null) === 'base';
            }
        } elseif ($command === 'incus.build-topology-seed') {
            $valid = $input['project'] === $host->project && $input['pool'] === $host->pool && $input['vm'] === $name.'-smoke'
                && $input['fingerprint'] === $fingerprint && $input['size'] === TaskVmVolumes::SeedSize;
        } elseif ($command === 'incus.volume') {
            $valid = $input['location'] === ['project' => $host->project, 'pool' => $host->pool]
                && $input['operation'] === ['kind' => 'finish_seed', 'fingerprint' => $fingerprint];
        }
        if (! $valid) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The host effect does not match this image attempt.', 422);
        }

        if ($operation->kind === 'task_vm.image_publish') {
            $ids = [];
            foreach ($build->steps as $step) {
                $ids += $step['operations'] ?? [];
            }
            $proofs = TaskVmImageEvidence::preparation($build, $ids);
            app(OperationStore::class)->change($operation, ['state' => [...($operation->state ?? []), 'task_vm_image_preparation' => $proofs]]);
        }

        return $this->effectResult($operation, $this->agents->run($build->hostNode, $command, AgentEffectReceipts::input($run, $input)));
    }

    /** @param array<string,mixed> $run */
    public function effectful(Operation $operation, array $run): bool
    {
        return true;
    }

    /** @param array<string,mixed> $run
     * @param  array<string,mixed>  $input
     * @return array{effect_key:string,state:string,result:array<string,mixed>|null,sanitized:bool,effect_nonce:string|null,effect_created_at:int|null,exit_code:int|null,finished_at:string|null}
     */
    public function receipt(Operation $operation, array $run, #[\SensitiveParameter] array $input): array
    {
        $build = TaskVmImageBuild::query()->findOrFail($operation->target_id);
        if ($operation->target_type !== 'task_vm_image' || $build->host_node_id !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The receipt host is not owned by this image.', 422);
        }
        $receipt = $this->targetReceipt($build->hostNode, ValidatedData::string($input['command'] ?? 'incus.image'), ValidatedData::string($run['effect_key'] ?? null));
        if ($receipt['state'] === 'succeeded') {
            $receipt['result'] = $this->effectResult($operation, $receipt['result'] ?? []);
        }

        return $receipt;
    }

    /** @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function effectResult(Operation $operation, array $result): array
    {
        if ($operation->kind === 'task_vm.image_publish' && (! is_string($result['fingerprint'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $result['fingerprint']) !== 1)) {
            return ['error_code' => 'task_vm.invalid_host_output'];
        }

        return ['ready' => true, ...$result, ...($operation->kind === 'task_vm.image_promote' ? ['fingerprint' => TaskVmImageBuild::query()->findOrFail($operation->target_id)->image_fingerprint] : [])];
    }
}
