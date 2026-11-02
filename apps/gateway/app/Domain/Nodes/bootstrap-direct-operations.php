<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeBootstrapIntent;
use App\Domain\Nodes\NodeBootstrapProjection;

$definitions = [];
foreach (NodeBootstrapIntent::Kinds as $kind) {
    $definitions[$kind] = ['workflow' => 'operation-'.str_replace(['.', '_'], ['-', '-'], $kind),
        'projection' => NodeBootstrapProjection::class];
    if ($kind === 'node.macos_inspect') {
        $definitions[$kind] += ['workflow_inputs' => true,
            'terminal_result' => ['job' => 'bootstrap', 'step' => 'Check the Mac', 'native' => true, 'captures' => [
                'architecture' => ['job' => 'bootstrap', 'step' => 'Inspect the Mac', 'field' => 'architecture'],
                'bootstrap_verified' => ['job' => 'bootstrap', 'step' => 'Inspect the Mac', 'field' => 'verified'],
                'error_code' => ['job' => 'bootstrap', 'step' => 'Inspect the Mac', 'field' => 'error_code']]],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^bootstrap-identity-node-14$']]];
    } else {
        $definitions[$kind] += ['workflow_inputs' => true, 'terminal_result' => ['job' => 'bootstrap', 'step' => 'probe-host'],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^bootstrap-identity-node-14$']]];
    }
}

return $definitions;
