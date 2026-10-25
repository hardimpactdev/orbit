<?php

declare(strict_types=1);

// Exercise the checker through its CLI. Fixtures contain invalid source; PHP owns the test and assertions.
$root = dirname(__DIR__, 4);
$directory = sys_get_temp_dir().'/orbit-lint-'.bin2hex(random_bytes(8));
mkdir($directory.'/tasks', 0700, true);
file_put_contents($directory.'/tasks/check-output.json', '{}');
try {
    foreach (['template', 'library'] as $location) {
        file_put_contents($directory.'/tasks/unit-state.py', $location === 'library' ? "unknown_library_name()\n" : "pass\n");
        file_put_contents($directory.'/topology.sh', "#!/bin/bash\npython3 - <<'PYTHON'\n# ORBIT_TASK_UNIT_STATE\n".($location === 'template' ? 'unknown_template_name' : 'pass')."\nPYTHON\n");
        $process = proc_open([$root.'/bin/remote-programs-check', '--resources', $directory], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not run the program checker.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $expected = ($location === 'template' ? '/topology.sh:4:1: F821' : '/tasks/unit-state.py:1:1: F821');
        $findings = array_values(array_filter(explode("\n", trim($stdout))));
        $reportedPath = explode(':', $findings[0] ?? '')[0];
        $expectedPath = $directory.($location === 'template' ? '/topology.sh' : '/tasks/unit-state.py');
        if ($status !== 1 || count($findings) !== 1 || realpath($root.'/'.$reportedPath) !== $expectedPath || ! str_ends_with(explode(': F821', $findings[0])[0].': F821', $expected)) {
            throw new RuntimeException("Incorrect {$location} source location (exit {$status}):\n{$stdout}{$stderr}");
        }
    }
    echo "Source location tests: 2 passed.\n";
} finally {
    foreach (['tasks/unit-state.py', 'tasks/check-output.json', 'topology.sh'] as $name) {
        if (is_file($directory.'/'.$name)) {
            unlink($directory.'/'.$name);
        }
    }
    rmdir($directory.'/tasks');
    rmdir($directory);
}
