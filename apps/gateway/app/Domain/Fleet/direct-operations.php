<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetRebootGitHubTokens;
use App\Domain\Fleet\FleetRebootProjection;
use App\Domain\Fleet\FleetReleaseProjection;
use App\Domain\Fleet\PeerDnsProjection;

return [
    'fleet.rollout' => [
        'workflow' => 'operation-fleet-rollout',
        'projection' => FleetReleaseProjection::class,
    ],
    'fleet.reboot' => [
        'workflow' => 'operation-fleet-reboot',
        'projection' => FleetRebootProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'pass', 'step' => 'Report the pass', 'data' => 'fleet-reboot-report'],
        'github_token_issuer' => FleetRebootGitHubTokens::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^fleet-reboot-github-operation-[1-9][0-9]*$']],
    ],
    'fleet.cli_converge' => [
        'workflow' => 'operation-fleet-cli-converge',
        'projection' => FleetReleaseProjection::class,
    ],
    'fleet.peer_dns' => [
        'workflow' => 'operation-fleet-peer-dns',
        'projection' => PeerDnsProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'dns', 'step' => 'Check resolution', 'native' => true],
    ],

];
