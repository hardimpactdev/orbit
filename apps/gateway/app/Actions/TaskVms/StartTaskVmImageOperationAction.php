<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentGuest;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\TaskVms\RecipeImageInput;
use App\Infrastructure\TaskVms\RecipeImagePruning;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;

/** Pins one image attempt as one Operation. No build jobs, queue chain or external effects. */
final readonly class StartTaskVmImageOperationAction
{
    public function __construct(private StartOperationAction $start, private TaskVmSettings $settings, private RecipeLoader $recipes,
        private RecipeImageInput $inputs, private SshKeyProvider $keys, private LeafCertificateSigner $certificates, private AgentDelivery $delivery, private RecipeImagePruning $pruning) {}

    public function execute(TaskVmImageBuild $build): Operation
    {
        $existing = Operation::query()->where('kind', 'task_vm.image_build')->where('target_type', 'task_vm_image')->where('target_id', $build->id)
            ->where('input->attempt', $build->attempt)->latest('id')->first();
        if ($existing !== null) {
            return $existing;
        }
        $host = $this->settings->host($build->host_node_id);
        $node = $build->hostNode;
        $recipe = $this->recipes->decode((string) $build->recipe_json);
        $payload = $this->inputs->payload(AgentGuest::incus($node, $host->project, (string) $build->builder_vm_name), $recipe, 'base');
        $architecture = $payload['recipe']['architecture'];
        $pi = $recipe->tools['pi']->artifacts[$architecture] ?? null;

        return $this->start->execute('task_vm.image_build', 'task_vm_image', $build->id, $node->id, [
            'build_id' => $build->id, 'attempt' => $build->attempt, 'host_node_id' => $node->id,
            'agent_sha256' => $this->delivery->pin($node), 'identity' => $recipe->identity, 'generation' => $build->recipe_generation,
            'project' => $host->project, 'name' => $build->builder_vm_name, 'cidr' => $host->cidr, 'pool' => $host->pool,
            'network' => $host->network, 'cpus' => $host->cpus, 'memory' => $host->memory, 'disk' => $host->disk,
            'source' => $build->source_fingerprint ?? TaskVmHost::SourceImage,
            'candidate_alias' => $build->images['base']['alias'] ?? RecipeImageBuilds::alias($host->imageAlias, $recipe->identity, 'base', $build->id),
            'base_alias' => $host->imageAlias, 'gateway_public_key' => $this->keys->publicKey(), 'certificate' => $this->certificates->rootCertificate(),
            'recipe' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'pi_artifact' => $pi === null ? null : ['path' => config()->string('orbit.home').'/sandbox-artifacts/'.$pi->location, 'sha256' => $pi->digest],
            'pi_artifact_file' => ['$secret' => 'task_vm.image_artifact', 'id' => $build->id],
            'prune' => $this->pruning->input($build),
        ], 'task-vm-image', null);
    }
}
