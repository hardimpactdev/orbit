<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Real CLI reports, shared read-only within a file/worker; assertions stay in the tests. */
final class FindingRuleAnalysis
{
    private static ?self $shared = null;

    /**
     * @param  array{int, array<string, mixed>, string}  $forbidden
     * @param  array{int, array<string, mixed>, string}  $allowed
     */
    private function __construct(
        public readonly string $directory,
        public readonly array $forbidden,
        public readonly array $allowed,
    ) {}

    public static function shared(): self
    {
        return self::$shared ??= self::prepare();
    }

    public static function cleanup(): void
    {
        if (self::$shared !== null) {
            new Filesystem()->deleteDirectory(self::$shared->directory);
            self::$shared = null;
        }
    }

    public function path(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    private static function prepare(): self
    {
        $directory = sys_get_temp_dir().'/orbit-finding-rules-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $sources = [
            'inline-var.php' => <<<'PHP'
                <?php
                final class InlineOverride
                {
                    public function forbiddenOverride(): void
                    {
                        /**
                         * @var string $value
                         */
                        $value = 'value';
                    }
                }
                PHP,
            'strtotime.php' => <<<'PHP'
                <?php
                function forbidden_call(): int
                {
                    return strtotime('2026-01-01');
                }
                PHP,
            'Allowed.php' => <<<'PHP'
                <?php
                final class Allowed
                {
                    /** @var string */
                    private string $property = 'value';

                    /**
                     * @param string $value
                     */
                    public function accept(string $value): string
                    {
                        // strtotime() in a comment is not a call.
                        return $value.$this->property;
                    }
                }
                PHP,
            'outside-method.php' => <<<'PHP'
                <?php
                /** @var string $global */
                $global = 'value';

                function allowed_function(): string
                {
                    /** @var string $value */
                    $value = 'value';

                    return $value;
                }
                PHP,
            'MethodBodyVar.php' => <<<'PHP'
                <?php
                final class MethodBodyVarFixture
                {
                    /** @var string */
                    private string $property = 'value';

                    /**
                     * @param string $parameter
                     * @return string
                     */
                    public function allowedDeclarationDocs(string $parameter): string
                    {
                        return $parameter.$this->property;
                    }

                    public function forbiddenMethodBodyOverride(): void
                    {
                        /** @var string $value */
                        $value = 'value';
                    }
                }
                PHP,
        ];
        foreach (['phpstan', 'psalm'] as $tool) {
            // Distinct classes let both annotation fixtures run in the same analyzer process.
            $sources[$tool.'-var.php'] = "<?php\nfinal class {$tool}TypedOverride {\n    public function override(): void {\n        /** @{$tool}-var string \$value */\n        \$value = 'value';\n    }\n}\n";
        }

        try {
            foreach ($sources as $name => $source) {
                file_put_contents($directory.'/'.$name, $source);
            }

            $forbidden = self::analyse([
                $directory.'/inline-var.php',
                $directory.'/strtotime.php',
                $directory.'/phpstan-var.php',
                $directory.'/psalm-var.php',
                $directory.'/MethodBodyVar.php',
                __DIR__.'/LifecycleAgent.php',
            ]);
            $allowed = self::analyse([$directory.'/Allowed.php', $directory.'/outside-method.php']);

            return new self($directory, $forbidden, $allowed);
        } catch (\Throwable $exception) {
            new Filesystem()->deleteDirectory($directory);

            throw $exception;
        }
    }

    /**
     * Decode stdout only: PHPStan can write AI-agent guidance to stderr.
     *
     * @param  list<string>  $paths
     * @return array{int, array<string, mixed>, string}
     */
    private static function analyse(array $paths): array
    {
        $process = new Process(
            ['./vendor/bin/phpstan', 'analyse', '--configuration=phpstan.neon', '--no-progress', '--error-format=json', ...$paths],
            env: ['PAO_DISABLE' => '1'],
        );
        $process->run();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($result)) {
            throw new RuntimeException('PHPStan did not return a JSON report: '.$process->getErrorOutput());
        }

        return [(int) $process->getExitCode(), $result, $process->getOutput().$process->getErrorOutput()];
    }
}
