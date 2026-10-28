<?php

declare(strict_types=1);

/** Integration extensions own their declarations beside their providers. */
return (static function (): array {
    $kinds = [];
    foreach (['vault', 'ai-router', 'conn'] as $family) {
        $path = base_path('extensions/'.$family.'/src/operation-kinds.php');
        if (is_file($path)) {
            $kinds = [...$kinds, ...require $path];
        }
    }

    return $kinds;
})();
