<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeBootstrapIntent;
use App\Domain\Nodes\NodeBootstrapProjection;
use App\Domain\Nodes\NodePhpPackagesProjection;

$definitions = [];
foreach (NodeBootstrapIntent::Kinds as $kind) {
    $definitions[$kind] = ['workflow' => 'operation-'.str_replace(['.', '_'], ['-', '-'], $kind),
        'projection' => NodeBootstrapProjection::class];
    if ($kind === 'node.bootstrap_probe') {
        $definitions[$kind] += ['workflow_inputs' => true, 'terminal_result' => ['job' => 'bootstrap', 'step' => 'probe-host']];
    } else {
        $definitions[$kind]['private_material'] = ['bootstrap_identity' => 'node.bootstrap_ssh_identity'];
    }
}
$definitions['node.prepare_php_packages'] = ['workflow' => 'operation-node-prepare-php-packages', 'projection' => NodePhpPackagesProjection::class];

return $definitions;
