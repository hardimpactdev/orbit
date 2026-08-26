<?php

declare(strict_types=1);

namespace Launch;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class BrowserTestRunner
{
    private ?Process $ssr = null;

    public function __construct(private readonly string $projectRoot) {}

    /**
     * @return array<string, string>
     */
    public function pestEnvironment(): array
    {
        return [
            ...getenv(),
            'INERTIA_SSR_THROW_ON_ERROR' => 'true',
            'INERTIA_SSR_ENABLED' => 'true',
        ];
    }

    public function ssrBundle(): string
    {
        return $this->projectRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'ssr'.DIRECTORY_SEPARATOR.'app.js';
    }

    protected function ssrPort(): int
    {
        return 13719;
    }

    public function ssrHealthUrl(): string
    {
        return 'http://127.0.0.1:'.$this->ssrPort().'/health';
    }

    /**
     * @param  list<string>  $passthrough
     */
    public function run(array $passthrough = [], bool $build = true): int
    {
        if ($build) {
            $this->buildAssets();
        }

        try {
            $this->startSsr();

            return $this->runPest($passthrough);
        } finally {
            $this->stopSsr();
        }
    }

    public function startSsr(): void
    {
        if (! is_file($this->ssrBundle())) {
            throw new RuntimeException('Inertia SSR bundle not found at ['.$this->ssrBundle().']. Build assets first.');
        }

        $runtime = (new ExecutableFinder)->find('node') ?? 'node';

        $this->ssr = new Process([$runtime, $this->ssrBundle()], $this->projectRoot);
        $this->ssr->setTimeout(null);
        $this->ssr->start();

        try {
            $this->waitUntilHealthy();
        } catch (\Throwable $exception) {
            $this->stopSsr();

            throw $exception;
        }
    }

    public function stopSsr(): void
    {
        if (! $this->ssr instanceof Process) {
            return;
        }

        if ($this->ssr->isRunning()) {
            $this->ssr->stop(3.0);
        }

        $this->ssr = null;
    }

    public function ssrIsOwnedAndRunning(): bool
    {
        return $this->ssr instanceof Process && $this->ssr->isRunning();
    }

    protected function buildAssets(): void
    {
        $build = new Process(['bun', 'run', 'build'], $this->projectRoot);
        $build->setTimeout(300);
        $build->run(static function (string $type, string $buffer): void {
            echo $buffer;
        });

        if (! $build->isSuccessful()) {
            throw new RuntimeException('Asset build failed with exit code '.($build->getExitCode() ?? 1).'.');
        }
    }

    /**
     * @param  list<string>  $passthrough
     */
    protected function runPest(array $passthrough): int
    {
        $arguments = [
            PHP_BINARY,
            $this->projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pest',
            'tests/Browser',
            ...$passthrough,
        ];

        $process = new Process($arguments, $this->projectRoot, $this->pestEnvironment());
        $process->setTimeout(null);

        if (Process::isTtySupported() && defined('STDOUT') && is_resource(STDOUT) && stream_isatty(STDOUT)) {
            $process->setTty(true);
            $process->run();
        } else {
            $process->run(static function (string $type, string $buffer): void {
                echo $buffer;
            });
        }

        return $process->getExitCode() ?? 1;
    }

    private function waitUntilHealthy(int $timeoutSeconds = 15): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if ($this->ssr instanceof Process && ! $this->ssr->isRunning()) {
                throw new RuntimeException('Inertia SSR process exited before becoming healthy: '.$this->ssr->getErrorOutput().$this->ssr->getOutput());
            }

            if ($this->healthCheckSucceeds()) {
                return;
            }

            usleep(100_000);
        }

        throw new RuntimeException('Inertia SSR server did not become healthy at '.$this->ssrHealthUrl().'.');
    }

    private function healthCheckSucceeds(): bool
    {
        $errno = 0;
        $error = '';
        $port = $this->ssrPort();
        $socket = false;
        set_error_handler(static fn (): bool => true);

        try {
            $socket = fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
        } finally {
            restore_error_handler();
        }

        if (! is_resource($socket)) {
            return false;
        }

        stream_set_timeout($socket, 0, 200_000);
        fwrite($socket, "GET /health HTTP/1.0\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n");
        $response = stream_get_contents($socket);
        fclose($socket);

        return is_string($response) && str_contains($response, '200');
    }
}
