<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallInspectionProjection;
use App\Domain\Firewall\FirewallIntentProjection;

$intent = [
    'workflow' => 'operation-firewall-direct',
    'projection' => FirewallIntentProjection::class,
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'firewall', 'step' => 'result'],
];

return [
    'firewall.inspect' => ['workflow' => 'operation-firewall-inspect', 'projection' => FirewallInspectionProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'inspection', 'step' => 'result']],
    'firewall.allow' => $intent,
    'firewall.deny' => $intent,
    'firewall.remove' => $intent,
];
