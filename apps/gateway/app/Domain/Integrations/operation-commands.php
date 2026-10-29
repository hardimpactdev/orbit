<?php

declare(strict_types=1);

/** Integration extensions own their named Gateway commands. */
return (static function (): array {
    $commands = [];
    foreach (['conn', 'ai-router'] as $family) {
        $path = base_path('extensions/'.$family.'/src/operation-commands.php');
        if (is_file($path)) {
            $commands = [...$commands, ...require $path];
        }
    }

    return $commands;
})();
