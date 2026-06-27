<?php

declare(strict_types=1);

namespace App\Librarian;

final readonly class CommandCatalogVerificationHints
{
    /**
     * @var array<string, string>
     */
    private const array REPO_ROOT_WRAPPERS = [
        'apps/cli' => 'bin/orbit-cli-pest',
        'apps/gateway' => 'bin/orbit-gateway-pest',
        'apps/docs' => 'bin/orbit-docs-pest',
    ];

    /**
     * @param  list<string>  $linkedTestFiles
     * @return list<array{
     *     repo_test_path: string,
     *     working_directory: string,
     *     runner: string,
     *     runner_test_path: string,
     *     suggested_command: string,
     * }>
     */
    public function forLinkedTestFiles(array $linkedTestFiles): array
    {
        $hints = [];

        foreach ($linkedTestFiles as $path) {
            $hint = $this->forLinkedTestFile($path);

            if ($hint !== null) {
                $hints[] = $hint;
            }
        }

        return $hints;
    }

    /**
     * @return array{
     *     repo_test_path: string,
     *     working_directory: string,
     *     runner: string,
     *     runner_test_path: string,
     *     suggested_command: string,
     * }|null
     */
    public function forLinkedTestFile(string $repoTestPath): ?array
    {
        $mapping = $this->resolveMapping($repoTestPath);

        if ($mapping === null) {
            return null;
        }

        return [
            'repo_test_path' => $repoTestPath,
            'working_directory' => $mapping['working_directory'],
            'runner' => $mapping['runner'],
            'runner_test_path' => $mapping['runner_test_path'],
            'suggested_command' => $mapping['suggested_command'],
        ];
    }

    /**
     * @return array{
     *     working_directory: string,
     *     runner: string,
     *     runner_test_path: string,
     *     suggested_command: string,
     * }|null
     */
    private function resolveMapping(string $repoTestPath): ?array
    {
        foreach (self::REPO_ROOT_WRAPPERS as $workingDirectory => $runner) {
            $mapping = $this->mappingForWorkingDirectory(
                repoTestPath: $repoTestPath,
                workingDirectory: $workingDirectory,
                runner: $runner,
            );

            if ($mapping !== null) {
                return $mapping;
            }
        }

        if (str_starts_with($repoTestPath, 'apps/e2e/tests/')) {
            return null;
        }

        $segments = explode('/', $repoTestPath, limit: 4);

        if (count($segments) !== 4) {
            return null;
        }

        if ($segments[0] !== 'packages' || $segments[1] === '' || $segments[2] !== 'tests') {
            return null;
        }

        if ($segments[3] === '') {
            return null;
        }

        $workingDirectory = "packages/{$segments[1]}";
        $runnerTestPath = "tests/{$segments[3]}";

        if (! str_starts_with($runnerTestPath, 'tests/')) {
            return null;
        }

        return [
            'working_directory' => $workingDirectory,
            'runner' => 'vendor/bin/pest',
            'runner_test_path' => $runnerTestPath,
            'suggested_command' => "cd {$workingDirectory} && vendor/bin/pest --compact {$runnerTestPath}",
        ];
    }

    /**
     * @return array{
     *     working_directory: string,
     *     runner: string,
     *     runner_test_path: string,
     *     suggested_command: string,
     * }|null
     */
    private function mappingForWorkingDirectory(
        string $repoTestPath,
        string $workingDirectory,
        string $runner,
    ): ?array {
        $prefix = "{$workingDirectory}/";

        if (! str_starts_with($repoTestPath, $prefix)) {
            return null;
        }

        $runnerTestPath = substr($repoTestPath, strlen($prefix));

        if (! str_starts_with($runnerTestPath, 'tests/')) {
            return null;
        }

        return [
            'working_directory' => $workingDirectory,
            'runner' => $runner,
            'runner_test_path' => $runnerTestPath,
            'suggested_command' => "{$runner} --compact {$runnerTestPath}",
        ];
    }
}
