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

    // Only disposable trial Projects may record the nested-fleet flag. Malformed JSON stays null and refuses.
    'nested_fleet_projects' => json_decode((string) (env('ORBIT_INSTANCES_NESTED_FLEET_PROJECTS') ?: '[]'), true),

    'container_nodes' => json_decode((string) (env('ORBIT_INSTANCES_CONTAINER_NODES') ?: '[]'), true),
];
