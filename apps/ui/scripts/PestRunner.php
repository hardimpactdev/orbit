<?php

declare(strict_types=1);

namespace Launch;

use Symfony\Component\Process\Process;

final class PestRunner
{
    public function __construct(private readonly string $projectRoot) {}

    /**
     * @return array<string, string>
     */
    public static function isolatedGitEnvironment(): array
    {
        return [
            ...getenv(),
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TEMPLATE_DIR' => '',
            'GIT_CONFIG_COUNT' => '2',
            'GIT_CONFIG_KEY_0' => 'commit.gpgsign',
            'GIT_CONFIG_VALUE_0' => 'false',
            'GIT_CONFIG_KEY_1' => 'core.hooksPath',
            'GIT_CONFIG_VALUE_1' => '/dev/null',
        ];
    }

    public function hasGitBaseline(): bool
    {
        $process = new Process(['git', 'rev-parse', '--verify', 'HEAD'], $this->projectRoot, self::isolatedGitEnvironment());
        $process->setTimeout(5.0);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $passthrough
     */
    public function shouldUseTia(array $passthrough = []): bool
    {
        return $this->isRepositoryRoot()
            && $this->hasGitBaseline()
            && ! $this->requestsSpecificTests($passthrough)
            && ! in_array('--tia', $passthrough, true);
    }

    private function isRepositoryRoot(): bool
    {
        $process = new Process(['git', 'rev-parse', '--show-toplevel'], $this->projectRoot, self::isolatedGitEnvironment());
        $process->setTimeout(5.0);
        $process->run();

        return $process->isSuccessful()
            && realpath(trim($process->getOutput())) === realpath($this->projectRoot);
    }

    /**
     * @param  list<string>  $passthrough
     * @return list<string>
     */
    public function pestArguments(array $passthrough = []): array
    {
        $arguments = [PHP_BINARY, $this->projectRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'pest'];

        if ($this->shouldUseTia($passthrough)) {
            $arguments[] = '--tia';
        }

        return array_merge($arguments, $passthrough);
    }

    /**
     * @param  list<string>  $passthrough
     */
    public function run(array $passthrough = []): int
    {
        $process = new Process($this->pestArguments($passthrough), $this->projectRoot);
        $process->setTimeout(null);

        if ($this->shouldUseTty()) {
            $process->setTty(true);
            $process->run();
        } else {
            $process->run(static function (string $type, string $buffer): void {
                echo $buffer;
            });
        }

        return $process->getExitCode() ?? 1;
    }

    /**
     * @param  list<string>  $passthrough
     */
    public function requestsSpecificTests(array $passthrough): bool
    {
        foreach ($passthrough as $argument) {
            if ($argument === '--filter' || str_starts_with($argument, '--filter=')) {
                return true;
            }

            if ($argument === '--testsuite' || str_starts_with($argument, '--testsuite=')) {
                return true;
            }

            if ($argument === '--group' || str_starts_with($argument, '--group=')) {
                return true;
            }

            if ($argument !== '' && ! str_starts_with($argument, '-')) {
                return true;
            }
        }

        return false;
    }

    private function shouldUseTty(): bool
    {
        return Process::isTtySupported()
            && defined('STDOUT')
            && is_resource(STDOUT)
            && stream_isatty(STDOUT);
    }
}
