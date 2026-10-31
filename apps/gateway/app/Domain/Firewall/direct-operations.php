<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallIntentProjection;

$intent = [
    'workflow' => 'operation-firewall-direct',
    'projection' => FirewallIntentProjection::class,
];

return [
    'firewall.allow' => $intent,
    'firewall.deny' => $intent,
    'firewall.remove' => $intent,
];
