<?php

declare(strict_types=1);

return array_fill_keys(['firewall.allow', 'firewall.deny', 'firewall.remove'], [
    'executor' => 'swamp', 'result_fields' => ['backend', 'error_code'],
]);
