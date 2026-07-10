<?php

declare(strict_types=1);

function unlink_if_exists(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
}

it('does not create NUL holes when idle replay writes to a file-captured stdout', function (): void {
    if (! function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for stream-json capture reproduction.');
    }

    $capturePath = tempnam(sys_get_temp_dir(), 'orbit-stream-json-capture-');

    if ($capturePath === false) {
        throw new RuntimeException('Could not allocate a capture file.');
    }

    $scriptPath = tempnam(sys_get_temp_dir(), 'orbit-stream-json-script-');

    if ($scriptPath === false) {
        unlink_if_exists($capturePath);

        throw new RuntimeException('Could not allocate a subprocess script file.');
    }

    $cliRoot = dirname(__DIR__, 3);
    $scriptPathWithPhp = "{$scriptPath}.php";
    rename($scriptPath, $scriptPathWithPhp);

    file_put_contents($scriptPathWithPhp, <<<PHP
        <?php

        declare(strict_types=1);

        require '{$cliRoot}/vendor/autoload.php';

        use App\Services\StreamJsonIdleStepWriter;

        \$writer = new StreamJsonIdleStepWriter();
        \$prefix = str_repeat('J', 877);

        fwrite(STDOUT, \$prefix);
        fflush(STDOUT);

        \$line = '{"event":"step","data":{"status":"running"}}'."\\n";
        \$writer->start(\$line, static fn (string \$chunk): int => fwrite(STDOUT, \$chunk), 1);
        usleep(1_100_000);
        \$writer->stop();

        fwrite(STDOUT, '{"event":"error"}'."\\n");
        fflush(STDOUT);
        PHP);

    $pipes = null;

    $process = proc_open(
        [PHP_BINARY, $scriptPathWithPhp],
        [
            0 => ['pipe', 'r'],
            1 => ['file', $capturePath, 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $cliRoot,
    );

    if (! is_resource($process)) {
        unlink_if_exists($capturePath);
        unlink_if_exists($scriptPathWithPhp);

        throw new RuntimeException('Could not start the stream-json capture subprocess.');
    }

    fclose($pipes[0]);
    fclose($pipes[2]);
    proc_close($process);

    $output = (string) file_get_contents($capturePath);
    unlink_if_exists($capturePath);
    unlink_if_exists($scriptPathWithPhp);

    expect($output)
        ->not
        ->toContain("\0")
        ->and(substr($output, 0, 877))
        ->toBe(str_repeat('J', 877));

    $jsonLines = array_values(array_filter(
        explode("\n", trim(substr($output, 877))),
        static fn (string $line): bool => $line !== '',
    ));

    foreach ($jsonLines as $line) {
        json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    expect($jsonLines)
        ->not
        ->toBeEmpty()
        ->and(end($jsonLines))
        ->toBe('{"event":"error"}');
})->group('slow');
