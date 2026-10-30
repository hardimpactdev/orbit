<?php

declare(strict_types=1);

return (static function (): array {
    $kinds = [];
    foreach (['sync', 'app_create', 'app_destroy', 'app_rotate'] as $verb) {
        $kinds['websocket.'.$verb] = ['executor' => 'swamp',
            'result_fields' => ['outcome', 'data.backend', 'data.error_code', 'error_code']];
    }

    return $kinds;
})();
