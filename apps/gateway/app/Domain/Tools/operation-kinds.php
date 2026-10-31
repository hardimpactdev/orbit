<?php

declare(strict_types=1);

return array_fill_keys(['tool.install', 'tool.update', 'tool.remove', 'tool.adopt', 'tool.manager_converge'], [
    'executor' => 'swamp',
    'result_fields' => ['manager_version', 'installed_version', 'block', 'outcome', 'error_code', 'native_error_code'],
]);
