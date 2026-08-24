<?php

declare(strict_types=1);

use Launch\BrowserTestRunner;

require dirname(__DIR__).'/vendor/autoload.php';

$passthrough = array_values(array_slice($argv, 1));

try {
    exit((new BrowserTestRunner(dirname(__DIR__)))->run($passthrough));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);

    exit(1);
}
