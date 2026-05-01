<?php

declare(strict_types=1);

it('documents the real-node e2e mode', function (): void {
    $script = base_path('bin/e2e');

    expect($script)->toBeFile()
        ->and(is_executable($script))->toBeTrue()
        ->and(file_get_contents($script))->toContain('ORBIT_E2E_GATEWAY_SSH')
        ->and(file_get_contents($script))->toContain('ORBIT_E2E_GATEWAY_PATH')
        ->and(file_get_contents($script))->toContain('update:all');
});
