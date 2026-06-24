<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

describe('CLI source entrypoint', function (): void {
    it('sets default launcher environment when invoked directly from source', function (): void {
        $capture = cliEntrypointProbe([]);

        expect($capture['ORBIT_APP'])
            ->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])
            ->toBe($capture['host_cwd'])
            ->and($capture['PWD'])
            ->toBe($capture['host_cwd'])
            ->and($capture['args'])
            ->toBe('[version]');
    });

    it('preserves a supplied host cwd when invoked directly from source', function (): void {
        $capture = cliEntrypointProbe([
            'ORBIT_HOST_CWD' => '/tmp/orbit-custom-cwd',
        ]);

        expect($capture['ORBIT_APP'])
            ->toBe('cli')
            ->and($capture['ORBIT_HOST_CWD'])
            ->toBe('/tmp/orbit-custom-cwd')
            ->and($capture['ENV_ORBIT_HOST_CWD'])
            ->toBe('/tmp/orbit-custom-cwd')
            ->and($capture['SERVER_ORBIT_HOST_CWD'])
            ->toBe('/tmp/orbit-custom-cwd')
            ->and($capture['PWD'])
            ->toBe($capture['host_cwd'])
            ->and($capture['args'])
            ->toBe('[version]');
    });

    it('preserves a supplied host cwd exactly, including surrounding spaces', function (): void {
        $capture = cliEntrypointProbe([
            'ORBIT_HOST_CWD' => '  /tmp/orbit-custom-cwd  ',
        ]);

        expect($capture['ORBIT_HOST_CWD'])
            ->toBe('  /tmp/orbit-custom-cwd  ')
            ->and($capture['ENV_ORBIT_HOST_CWD'])
            ->toBe('  /tmp/orbit-custom-cwd  ')
            ->and($capture['SERVER_ORBIT_HOST_CWD'])
            ->toBe('  /tmp/orbit-custom-cwd  ');
    });
});

/**
 * @param  array<string, string>  $environment
 * @return array<string, string>
 */
function cliEntrypointProbe(array $environment): array
{
    $root = sys_get_temp_dir().'/orbit-cli-entrypoint-'.bin2hex(random_bytes(4));

    try {
        $checkout = "{$root}/checkout";
        $hostCwd = "{$root}/caller/project";
        $capturePath = "{$root}/entrypoint-capture";

        cliEntrypointPrepareFakeCheckout($checkout, $capturePath);
        File::ensureDirectoryExists($hostCwd);

        $process = new Process(
            [$checkout.'/apps/cli/orbit', '--version'],
            $hostCwd,
            $environment + ['HOME' => "{$root}/home"],
        );

        $process->run();

        expect($process->getExitCode())
            ->toBe(
                0,
                $process->getErrorOutput().$process->getOutput(),
            );
        expect(File::exists($capturePath))->toBeTrue('expected the CLI entrypoint to execute the fake kernel');

        return cliEntrypointReadCapture($capturePath) + ['host_cwd' => realpath($hostCwd) ?: $hostCwd];
    } finally {
        if (is_dir($root)) {
            File::deleteDirectory($root);
        }
    }
}

function cliEntrypointPrepareFakeCheckout(string $checkout, string $capturePath): void
{
    $sourceRoot = dirname(__DIR__, 2);

    File::ensureDirectoryExists("{$checkout}/apps/cli/bootstrap");
    File::ensureDirectoryExists("{$checkout}/apps/cli/vendor");

    File::copy("{$sourceRoot}/orbit", "{$checkout}/apps/cli/orbit");
    File::copy("{$sourceRoot}/NativeCommandNormalizer.php", "{$checkout}/apps/cli/NativeCommandNormalizer.php");
    chmod("{$checkout}/apps/cli/orbit", 0755);

    File::put("{$checkout}/apps/cli/vendor/autoload.php", <<<'PHP'
        <?php
        declare(strict_types=1);

        namespace Illuminate\Contracts\Console {
            interface Kernel
            {
                public function bootstrap(): void;

                public function handle($input, $output = null): int;

                public function terminate($input, $status): void;

                public function call($command, array $parameters = [], $outputBuffer = null): int;

                public function queue($command, array $parameters = []): never;

                public function all(): array;

                public function output(): string;
            }
        }

        namespace Symfony\Component\Console\Input {
            class ArgvInput {}
        }

        namespace Symfony\Component\Console\Output {
            class ConsoleOutput {}
        }
        PHP);

    File::put("{$checkout}/apps/cli/bootstrap/app.php", <<<PHP
        <?php

        declare(strict_types=1);

        use Illuminate\Contracts\Console\Kernel;
        use Symfony\Component\Console\Input\InputInterface;
        use Symfony\Component\Console\Output\OutputInterface;

        return new class ('{$capturePath}') {
            public function __construct(private readonly string \$capturePath) {}

            public function make(string \$abstract): object
            {
                if (\$abstract !== Kernel::class) {
                    throw new RuntimeException('Unexpected abstract: '.\$abstract);
                }

                return new class (\$this->capturePath) implements Kernel {
                    public function __construct(private readonly string \$capturePath) {}

                    public function bootstrap(): void {}

                    public function handle(\$input, \$output = null): int
                    {
                        file_put_contents(\$this->capturePath, json_encode([
                            'ORBIT_APP' => getenv('ORBIT_APP') ?: '',
                            'ORBIT_HOST_CWD' => getenv('ORBIT_HOST_CWD') ?: '',
                            'ENV_ORBIT_HOST_CWD' => \$_ENV['ORBIT_HOST_CWD'] ?? '',
                            'SERVER_ORBIT_HOST_CWD' => \$_SERVER['ORBIT_HOST_CWD'] ?? '',
                            'PWD' => getcwd() ?: '',
                            'args' => implode('', array_map(
                                static fn (string \$argument): string => '['.\$argument.']',
                                array_slice(\$_SERVER['argv'] ?? [], 1),
                            )),
                        ], JSON_THROW_ON_ERROR));

                        return 0;
                    }

                    public function terminate(\$input, \$status): void {}

                    public function call(\$command, array \$parameters = [], \$outputBuffer = null): int
                    {
                        throw new BadMethodCallException('Not implemented.');
                    }

                    public function queue(\$command, array \$parameters = []): never
                    {
                        throw new BadMethodCallException('Not implemented.');
                    }

                    public function all(): array
                    {
                        return [];
                    }

                    public function output(): string
                    {
                        return '';
                    }
                };
            }
        };
        PHP);
}

/**
 * @return array<string, string>
 */
function cliEntrypointReadCapture(string $capturePath): array
{
    /** @var array<string, string> $decoded */
    $decoded = json_decode(File::get($capturePath), true, flags: JSON_THROW_ON_ERROR);

    return $decoded;
}
