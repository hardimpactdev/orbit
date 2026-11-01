<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Domain\TaskVms\TaskVmHubProjection;
use App\Domain\TaskVms\TaskVmImageProjection;
use App\Domain\TaskVms\TaskVmIncusProjection;
use App\Domain\TaskVms\TaskVmLifecycleIntentProjection;
use App\Domain\TaskVms\TaskVmPackageCacheProjection;
use App\Domain\TaskVms\TaskVmProvisionProjection;
use App\Domain\TaskVms\TaskVmResumeProjection;
use App\Domain\TaskVms\TaskVmSweepProjection;
use App\Domain\TaskVms\TaskVmUpCloudImageProjection;

return ['task_vm.hub_converge' => [
    'workflow' => 'operation-task-vm-hub-converge',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'hub', 'step' => 'result'],
    'projection' => TaskVmHubProjection::class,
], 'task_vm.resume' => [
    'workflow' => 'operation-task-vm-resume',
    'projection' => TaskVmResumeProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'resume', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^task-vm-upcloud-token$']],
], 'task_vm.provision' => [
    'workflow' => 'operation-task-vm-provision',
    'projection' => TaskVmProvisionProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'provision', 'step' => 'result'],
    'private_material' => [],
    'secret_outputs' => [],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(task-vm-(model-key|pi-token)-[1-9][0-9]*|model-router-(management|anchor)-node-[1-9][0-9]*|task-vm-upcloud-token)$']],
], 'task_vm.enroll' => [
    'workflow' => 'operation-task-vm-enroll',
    'projection' => TaskVmEnrollmentProjection::class,
    'private_material' => ['installation' => 'task_vm.installation', 'configuration' => 'node.vpn_configuration', 'first_visit' => 'task_vm.first_visit', 'model_key' => 'task_vm.model_key', 'pi_token' => 'task_vm.pi_token', 'model_router' => 'task_vm.model_router', 'pi_artifact_file' => 'task_vm.image_artifact', 'secret_hash' => 'node.agent_secret_hash', 'provider_bootstrap' => 'task_vm.provider_bootstrap'],
    'secret_outputs' => ['secret_hash' => 'node.agent_secret_hash', 'provider_bootstrap' => 'task_vm.provider_bootstrap'],
], 'task_vm.prepare_runtime' => [
    'workflow' => 'operation-task-vm-prepare-runtime',
    'projection' => TaskVmEnrollmentProjection::class,
    'private_material' => ['installation' => 'task_vm.installation', 'configuration' => 'node.vpn_configuration', 'first_visit' => 'task_vm.first_visit', 'model_key' => 'task_vm.model_key', 'pi_token' => 'task_vm.pi_token', 'model_router' => 'task_vm.model_router', 'pi_artifact_file' => 'task_vm.image_artifact', 'secret_hash' => 'node.agent_secret_hash', 'provider_bootstrap' => 'task_vm.provider_bootstrap'],
    'secret_outputs' => ['secret_hash' => 'node.agent_secret_hash', 'provider_bootstrap' => 'task_vm.provider_bootstrap'],
]] + array_fill_keys(TaskVmIncusProjection::Kinds, [
    'workflow' => 'operation-task-vm-incus',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_incus_writer',
    'projection' => TaskVmIncusProjection::class,
]) + ['task_vm.lifecycle_intent' => [
    'workflow' => 'operation-task-vms',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_lifecycle_intent_writer',
    'projection' => TaskVmLifecycleIntentProjection::class,
], 'task_vm.fill_package_cache' => [
    'workflow' => 'operation-task-vm-cache-fill',
    'projection' => TaskVmPackageCacheProjection::class,
    'private_material' => ['github_token' => 'task_vm.package_cache_github'],
], 'task_vm.image_build' => [
    'workflow' => 'operation-task-vm-image-build',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_image_writer',
    'projection' => TaskVmImageProjection::class,
    'workflow_inputs' => true,
    'private_material' => ['pi_artifact_file' => 'task_vm.image_artifact'],
], 'task_vm.sweep_host' => [
    'workflow' => 'operation-task-vm-sweep',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_sweep_writer',
    'projection' => TaskVmSweepProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api'],
], 'task_vm.upcloud_image_build' => [
    'workflow' => 'operation-task-vm-upcloud-image-build',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'task_vm_upcloud_image_writer',
    'projection' => TaskVmUpCloudImageProjection::class,
    'private_material' => ['upcloud_token' => 'upcloud.api', 'bootstrap' => 'task_vm.image_bootstrap', 'pi_artifact_file' => 'task_vm.image_artifact'],
    'secret_outputs' => ['bootstrap' => 'task_vm.image_bootstrap'],
]];
