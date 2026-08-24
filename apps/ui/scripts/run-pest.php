<?php

declare(strict_types=1);

use Launch\PestRunner;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

$projectRoot = dirname(__DIR__);
$passthrough = array_values(array_slice($argv, 1));

$clear = new Process([PHP_BINARY, $projectRoot.DIRECTORY_SEPARATOR.'artisan', 'config:clear', '--ansi'], $projectRoot);
$clear->setTimeout(60);
$clear->run(static function (string $type, string $buffer): void {
    echo $buffer;
});

if ($clear->getExitCode() !== 0) {
    exit($clear->getExitCode() ?? 1);
}

exit((new PestRunner($projectRoot))->run($passthrough));
