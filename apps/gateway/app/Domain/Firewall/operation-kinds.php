<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallOperationCompletion;

return array_fill_keys(['firewall.allow', 'firewall.deny', 'firewall.remove'], [
    'executor' => 'swamp',
    'completion' => FirewallOperationCompletion::class,
]);
