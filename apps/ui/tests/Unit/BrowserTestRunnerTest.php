<?php

declare(strict_types=1);

use Launch\BrowserTestRunner;

it('builds assets through vp', function () {
    expect(file_get_contents(base_path('scripts/BrowserTestRunner.php')))
        ->toContain("new Process(['vp', 'run', 'build']");
});

function launchBrowserRunnerScratch(): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'launch-browser-'.bin2hex(random_bytes(8));

    mkdir($directory);

    return $directory;
}

function launchBrowserRunnerFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');

    if (! is_resource($socket)) {
        throw new RuntimeException('Unable to allocate a loopback port for the browser test runner.');
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    if (! is_string($address) || ! str_contains($address, ':')) {
        throw new RuntimeException('Unable to read the allocated loopback port.');
    }

    $port = (int) substr($address, (int) strrpos($address, ':') + 1);

    if ($port < 1) {
        throw new RuntimeException('Unable to parse the allocated loopback port.');
    }

    return $port;
}

function launchBrowserRunnerWriteBundle(string $directory, string $source): void
{
    $ssrDirectory = $directory.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'ssr';

    mkdir($ssrDirectory, 0777, true);
    file_put_contents($ssrDirectory.DIRECTORY_SEPARATOR.'app.js', $source);
}

function launchBrowserRunnerHealthyBundle(int $port): string
{
    return <<<JS
const http = require('node:http');

const server = http.createServer((request, response) => {
    if (String(request.url || '').startsWith('/health')) {
        response.writeHead(200, { 'Content-Type': 'text/plain' });
        response.end('ok');

        return;
    }

    response.writeHead(404);
    response.end();
});

server.listen({$port}, '127.0.0.1');
JS;
}

function launchBrowserRunnerCleanup(string $directory): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($directory);
}

final class LaunchBrowserRunnerOnPort extends BrowserTestRunner
{
    public function __construct(
        string $projectRoot,
        private readonly int $testSsrPort,
    ) {
        parent::__construct($projectRoot);
    }

    protected function ssrPort(): int
    {
        return $this->testSsrPort;
    }
}

it('forces inertia ssr to throw so browser tests cannot silently fall back to csr', function () {
    $runner = new BrowserTestRunner(dirname(__DIR__, 2));

    expect($runner->pestEnvironment())
        ->toHaveKey('INERTIA_SSR_THROW_ON_ERROR', 'true')
        ->toHaveKey('INERTIA_SSR_ENABLED', 'true')
        ->and($runner->ssrBundle())->toEndWith('/bootstrap/ssr/app.js')
        ->and($runner->ssrHealthUrl())->toBe('http://127.0.0.1:13719/health');
});

it('fails closed with an actionable message when the ssr bundle is missing', function () {
    $directory = launchBrowserRunnerScratch();
    $runner = new BrowserTestRunner($directory);

    $gated = new class($directory) extends BrowserTestRunner
    {
        public bool $pestRan = false;

        protected function runPest(array $passthrough): int
        {
            $this->pestRan = true;

            return 0;
        }

        protected function buildAssets(): void {}
    };

    try {
        expect(fn () => $runner->startSsr())
            ->toThrow(function (RuntimeException $exception) use ($runner): void {
                expect($exception->getMessage())
                    ->toContain($runner->ssrBundle())
                    ->toContain('Build assets first');
            })
            ->and($runner->ssrIsOwnedAndRunning())->toBeFalse()
            ->and(fn () => $gated->run(build: false))
            ->toThrow(function (RuntimeException $exception) use ($gated): void {
                expect($exception->getMessage())
                    ->toContain($gated->ssrBundle())
                    ->toContain('Build assets first');
            })
            ->and($gated->pestRan)->toBeFalse()
            ->and($gated->ssrIsOwnedAndRunning())->toBeFalse();
    } finally {
        $runner->stopSsr();
        $gated->stopSsr();
        launchBrowserRunnerCleanup($directory);
    }
});

it('starts the built ssr server and stops only the owned process', function () {
    $directory = launchBrowserRunnerScratch();
    $port = launchBrowserRunnerFreePort();
    $runner = new LaunchBrowserRunnerOnPort($directory, $port);

    launchBrowserRunnerWriteBundle($directory, launchBrowserRunnerHealthyBundle($port));

    try {
        $runner->startSsr();

        expect($runner->ssrIsOwnedAndRunning())->toBeTrue()
            ->and($runner->ssrHealthUrl())->toBe('http://127.0.0.1:'.$port.'/health');
    } finally {
        $runner->stopSsr();
        launchBrowserRunnerCleanup($directory);
    }

    expect($runner->ssrIsOwnedAndRunning())->toBeFalse();
});

it('cleans up an owned ssr process that exits before becoming healthy', function () {
    $directory = launchBrowserRunnerScratch();
    $runner = new LaunchBrowserRunnerOnPort($directory, launchBrowserRunnerFreePort());

    launchBrowserRunnerWriteBundle($directory, "process.exit(1);\n");

    try {
        expect(fn () => $runner->startSsr())
            ->toThrow(RuntimeException::class, 'exited before becoming healthy')
            ->and($runner->ssrIsOwnedAndRunning())->toBeFalse();
    } finally {
        $runner->stopSsr();
        launchBrowserRunnerCleanup($directory);
    }
});

it('stops an owned ssr process even when pest exits non-zero', function () {
    $runner = new class(dirname(__DIR__, 2)) extends BrowserTestRunner
    {
        public int $stopCalls = 0;

        public function startSsr(): void {}

        public function stopSsr(): void
        {
            $this->stopCalls++;
        }

        protected function runPest(array $passthrough): int
        {
            return 2;
        }

        protected function buildAssets(): void {}
    };

    expect($runner->run(build: false))->toBe(2)
        ->and($runner->stopCalls)->toBe(1);
});
