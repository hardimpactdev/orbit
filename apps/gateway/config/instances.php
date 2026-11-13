<?php

declare(strict_types=1);

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

    // The release refresh of nested-fleet defaults (docs/reference/development-containers.md#refresh-after-a-release). Off by default:
    // an operator turns it on once the Club key lives off the default's disk and a safety snapshot exists.
    'nested_fleet_refresh' => filter_var(env('ORBIT_INSTANCES_NESTED_FLEET_REFRESH', false), FILTER_VALIDATE_BOOL),

    'container_nodes' => json_decode((string) (env('ORBIT_INSTANCES_CONTAINER_NODES') ?: '[]'), true),
];
