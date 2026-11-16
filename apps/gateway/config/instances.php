<?php

declare(strict_types=1);
use App\Domain\Instances\InstanceFleetGateway;

/*
 * Instances in containers (ADR 0250, step 6). App\Domain\Instances\ContainerNodes reads these values.
 */

return [
    // The build list: a JSON list of the Node ids that run container Instances during the build
    // (docs/solutions/development-container-runtime.md#rules-for-every-pr). The switch to every app-dev Node removes it.
    // Malformed JSON stays null and fails the read.
    // The main Node-owned Mailpit; only its active, desired-running Docker publication enables mail.
    'mailpit' => ['process' => 'mailpit', 'port' => 1025],

    // Only disposable trial Projects may record the nested-fleet flag: a JSON list of positive integer Project ids.
    // A JSON object, even {} or {"0":123}, stays an object and malformed JSON stays null; both refuse.
    'nested_fleet_projects' => json_decode((string) (env('ORBIT_INSTANCES_NESTED_FLEET_PROJECTS') ?: '[]')),

    // The trial Projects whose container default instance:destroy may remove, if it has an inner volume and no copy
    // (docs/reference/development-containers.md#destroy): a JSON list of positive integer Project ids, like the list above.
    'default_destroy_projects' => json_decode((string) (env('ORBIT_INSTANCES_DEFAULT_DESTROY_PROJECTS') ?: '[]')),

    // The removal reaper that starts failed destroys again (docs/reference/instance-removal.md#the-removal-reaper). On by default; off,
    // its scheduled pass does nothing.
    'removal_reaper' => filter_var(env('ORBIT_INSTANCE_REMOVAL_REAPER', true), FILTER_VALIDATE_BOOL),

    // The release refresh of nested-fleet defaults (docs/reference/development-containers.md#refresh-after-a-release). Off by default:
    // an operator turns it on once the Club key lives off the default's disk and a safety snapshot exists.
    'nested_fleet_refresh' => filter_var(env('ORBIT_INSTANCES_NESTED_FLEET_REFRESH', false), FILTER_VALIDATE_BOOL),

    // The root disk size of a nested-fleet default, `<n>GiB`, at least 40GiB (docs/reference/development-containers.md#the-root-disk).
    // Its quota also counts the seeds that its copies pin; every other container keeps 40GiB.
    'nested_default_root_size' => env('ORBIT_INSTANCES_NESTED_DEFAULT_ROOT_SIZE', '80GiB'),

    // The marker file of the nested Gateway of an Instance container's fleet (InstanceFleetGateway). Tests point it at a file of their own.
    'fleet_marker' => InstanceFleetGateway::Marker,
    'fleet_environment' => InstanceFleetGateway::Environment,

    'container_nodes' => json_decode((string) (env('ORBIT_INSTANCES_CONTAINER_NODES') ?: '[]'), true),
];
