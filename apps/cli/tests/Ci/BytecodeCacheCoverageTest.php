<?php

declare(strict_types=1);

pest()->group('subprocess');

it('records PCOV coverage for cold and cached bytecode and rewritten source', function (): void {
    $program = <<<'PHP'
        $cached = opcache_is_script_cached_in_file_cache($argv[1]);
        \pcov\start();
        $value = require $argv[1];
        $coverage = \pcov\collect();
        echo json_encode(['cached_before' => $cached, 'value' => $value, 'coverage' => $coverage[$argv[1]]], JSON_THROW_ON_ERROR);
        PHP;

    assert_cli_bytecode_cache(
        $program,
        ['value' => 'initial', 'coverage' => [2 => 1]],
        ['value' => 'changed', 'coverage' => [2 => 1]],
        ['pcov.enabled' => '1', 'pcov.directory' => sys_get_temp_dir()],
    );
});
