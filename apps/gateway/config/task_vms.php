<?php

declare(strict_types=1);

/*
 * Task VMs: one disposable `app-dev` Node per task group. Off by default.
 * App\Domain\TaskVms\TaskVmSettings validates these values once, whenever they are set; read
 * them through it. Unset values stay absent. Malformed JSON becomes null and fails validation.
 */
return [
    'enabled' => (bool) env('ORBIT_TASK_VMS_ENABLED', false),
    'dev_cluster_id' => env('ORBIT_TASK_VMS_DEV_CLUSTER_ID'),
    'wireguard_range' => env('ORBIT_TASK_VMS_WIREGUARD_RANGE') ?: '10.44.0.128/25',
    'model_proxy_origin' => env('ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN'),
    // Minutes a group waits for review before its task VM stops. Its next work starts the VM again.
    'park_after_minutes' => env('ORBIT_TASK_VMS_PARK_AFTER_MINUTES'),
    // A JSON list of Project ids whose groups without a declared topology get a medium VM: 4 vCPU, 8 GiB, 2 slots.
    'medium_projects' => json_decode((string) (env('ORBIT_TASK_VMS_MEDIUM_PROJECTS') ?: '[]'), true),
    'pi' => [
        'artifact_path' => env('ORBIT_TASK_VMS_PI_ARTIFACT_PATH'),
        'artifact_sha256' => env('ORBIT_TASK_VMS_PI_ARTIFACT_SHA256'),
        'models' => json_decode((string) (env('ORBIT_TASK_VMS_PI_MODELS') ?: '[]'), true),
    ],
    'incus' => [
        /*
         * A JSON list of hosts: node_id, cidr (the bridge network) and max_vms are required;
         * project (orbit-tasks), network (orbittask0), cpus (2),
         * memory (4GiB), disk (20GiB), pool (orbit-tasks, a ZFS pool), zfs_dataset (to create
         * the pool), and warm_vms (0: how many enrolled VMs without a group the host keeps ready for
         * claims, within max_vms) are optional. Task VMs launch the base image `orbit-task-base`.
         */
        'hosts' => json_decode((string) (env('ORBIT_TASK_VMS_INCUS_HOSTS') ?: '[]'), true),
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
        'zone' => env('ORBIT_TASK_VMS_UPCLOUD_ZONE'),
        'plan' => env('ORBIT_TASK_VMS_UPCLOUD_PLAN'),
        'disk_gb' => env('ORBIT_TASK_VMS_UPCLOUD_DISK_GB'),
        'source_template' => env('ORBIT_TASK_VMS_UPCLOUD_SOURCE_TEMPLATE'),
    ],
];
