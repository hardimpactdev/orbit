<?php

declare(strict_types=1);

/*
 * Task VMs: one disposable `app-dev` Node per task group. Off by default.
 * App\Domain\TaskVms\TaskVmSettings validates these values once, whenever they are set; read
 * them through it. Every default lives here. Unset values without a default stay absent. Malformed
 * JSON becomes null and fails validation.
 */

/** The environment value, or the default while it is unset or empty. "0" counts as set. */
$env = static fn (string $key, mixed $default): mixed => filled(env($key)) ? env($key) : $default;

return [
    'enabled' => (bool) env('ORBIT_TASK_VMS_ENABLED', false),
    'dev_cluster_id' => env('ORBIT_TASK_VMS_DEV_CLUSTER_ID'),
    'wireguard_range' => env('ORBIT_TASK_VMS_WIREGUARD_RANGE') ?: '10.44.0.128/25',
    'model_proxy_origin' => env('ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN'),
    // Minutes a group waits for review before its task VM stops. Its next work starts the VM again.
    'park_after_minutes' => $env('ORBIT_TASK_VMS_PARK_AFTER_MINUTES', 30),
    // A JSON list of Project ids whose groups without a declared topology get a medium VM (TaskVmSize::resources()).
    'medium_projects' => json_decode((string) (env('ORBIT_TASK_VMS_MEDIUM_PROJECTS') ?: '[]'), true),
    // A JSON object from Project id to path patterns, such as {"46":["apps/agent/**"]}. A handoff that changes one fails
    // while no subtask of the group declares a topology.
    'topology_paths' => json_decode((string) (env('ORBIT_TASK_VMS_TOPOLOGY_PATHS') ?: '{}'), true),
    'pi' => [
        'artifact_path' => env('ORBIT_TASK_VMS_PI_ARTIFACT_PATH'),
        'artifact_sha256' => env('ORBIT_TASK_VMS_PI_ARTIFACT_SHA256'),
        'models' => json_decode((string) (env('ORBIT_TASK_VMS_PI_MODELS') ?: '[]'), true),
    ],
    'incus' => [
        /*
         * A JSON list of hosts: node_id, cidr (the bridge network) and max_vms are required; the keys of
         * host_defaults are optional. pool is a ZFS pool, zfs_dataset creates it, and warm_vms is how many
         * enrolled VMs without a group the host keeps ready for claims, within max_vms. Task VMs launch the
         * base image `orbit-task-base`.
         */
        'hosts' => json_decode((string) (env('ORBIT_TASK_VMS_INCUS_HOSTS') ?: '[]'), true),
        'host_defaults' => [
            'project' => 'orbit-tasks',
            'network' => 'orbittask0',
            'cpus' => 2,
            'memory' => '4GiB',
            'disk' => '20GiB',
            'pool' => 'orbit-tasks',
            'zfs_dataset' => null,
            'warm_vms' => 0,
            // Incus instances and custom volumes the operator owns, as <project>/<name> or <project>/*: the stray report skips them.
            'operator_owned' => [],
        ],
    ],
    /*
     * UpCloud overflow: a `vm` group of an opted-in Project gets an UpCloud task VM when no Incus host has a free
     * slot. Off by default. Unset token_file means not configured; enabled needs it, max_vms, and task VMs enabled.
     */
    'upcloud' => [
        'enabled' => (bool) env('ORBIT_TASK_VMS_UPCLOUD_ENABLED', false),
        'projects' => json_decode((string) (env('ORBIT_TASK_VMS_UPCLOUD_PROJECTS') ?: '[]'), true),
        'token_file' => env('ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE'),
        'max_vms' => env('ORBIT_TASK_VMS_UPCLOUD_MAX_VMS'),
        'zone' => $env('ORBIT_TASK_VMS_UPCLOUD_ZONE', 'nl-ams1'),
        'plan' => $env('ORBIT_TASK_VMS_UPCLOUD_PLAN', 'STARTER-2xCPU-4GB'),
        'disk_gb' => $env('ORBIT_TASK_VMS_UPCLOUD_DISK_GB', 30),
        // Ubuntu 26.04.
        'source_template' => $env('ORBIT_TASK_VMS_UPCLOUD_SOURCE_TEMPLATE', '01000000-0000-4000-8000-000030260200'),
    ],
];
