<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use Orbit\Core\Security\SecretSummaryRedactor;
use SensitiveParameter;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class RemoteExecutorOutputRedactor
{
    public const string REDACTED_VALUE = '<redacted>';

    private const int OUTPUT_SUMMARY_BYTES = 4_096;

    private const string TRUNCATED_SUFFIX = '[truncated]';

    private const string SUPPRESSED_OUTPUT_SUMMARY = '<suppressed>';

    public function __construct(
        private SecretSummaryRedactor $secrets,
    ) {}

    public function sanitizeResult(
        RemoteShellResult $result,
        #[SensitiveParameter]
        string $operationToken,
    ): RemoteShellResult {
        return new RemoteShellResult(
            exitCode: $result->exitCode,
            stdout: $this->redactOperationToken($result->stdout, $operationToken),
            stderr: $this->redactOperationToken($result->stderr, $operationToken),
            durationMs: $result->durationMs,
        );
    }

    public function summarizeOutput(
        string $output,
        #[SensitiveParameter]
        string $operationToken,
        bool $suppress = false,
    ): string {
        if ($suppress) {
            return self::SUPPRESSED_OUTPUT_SUMMARY;
        }

        return $this->truncate($this->redactTransportText($output, $operationToken));
    }

    public function redactTransportText(string $value, #[SensitiveParameter] string $operationToken): string
    {
        return $this->secrets->redactString($this->redactOperationToken($value, $operationToken));
    }

    public function redactCommandOptionsInLine(
        string $value,
        LocalExecutorTransportOptions $transportOptions,
    ): string {
        return $this->redactNamedCommandOptions($value, $transportOptions->redactedCommandOptionNames());
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     */
    public function exceptionMessageSummary(
        Throwable $throwable,
        #[SensitiveParameter]
        string $operationToken,
        LocalExecutorTransportOptions $transportOptions,
        array $commandOptions,
    ): string {
        return $this->summarizeOutput(
            output: $this->redactExceptionText(
                value: $throwable->getMessage(),
                operationToken: $operationToken,
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            ),
            operationToken: $operationToken,
            suppress: $transportOptions->redactStdout() || $transportOptions->redactStderr(),
        );
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @return array<array-key, mixed>
     */
    public function exceptionMetadata(
        Throwable $throwable,
        #[SensitiveParameter]
        string $operationToken,
        LocalExecutorTransportOptions $transportOptions,
        array $commandOptions,
    ): array {
        $metadata = $this->rawExceptionMetadata($throwable);

        if ($metadata === []) {
            return [];
        }

        return $this->redactExceptionMetadata(
            metadata: $metadata,
            operationToken: $operationToken,
            transportOptions: $transportOptions,
            commandOptions: $commandOptions,
        );
    }

    private function redactOperationToken(string $value, #[SensitiveParameter] string $operationToken): string
    {
        $redacted =
            preg_replace(
                '/--operation-token\s*(?:=\s*|\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
                '--operation-token='.self::REDACTED_VALUE,
                $value,
            ) ?? $value;

        if ($operationToken === '') {
            return $redacted;
        }

        return str_replace($operationToken, self::REDACTED_VALUE, $redacted);
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     */
    private function redactExceptionText(
        string $value,
        #[SensitiveParameter]
        string $operationToken,
        LocalExecutorTransportOptions $transportOptions,
        array $commandOptions,
    ): string {
        return $this->redactTransportText(
            value: $this->redactCommandOptionSecrets($value, $transportOptions, $commandOptions),
            operationToken: $operationToken,
        );
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     */
    private function redactCommandOptionSecrets(
        string $value,
        LocalExecutorTransportOptions $transportOptions,
        array $commandOptions,
    ): string {
        $optionNames = $transportOptions->redactedCommandOptionNames();

        if ($optionNames === []) {
            return $value;
        }

        $redacted = $this->redactNamedCommandOptions($value, $optionNames);

        foreach ($this->redactedCommandOptionValues($commandOptions, $optionNames) as $optionValue) {
            $redacted = str_replace($optionValue, self::REDACTED_VALUE, $redacted);
        }

        return $redacted;
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @param  array<int|string, mixed>  $commandOptions
     * @return array<array-key, mixed>
     */
    private function redactExceptionMetadata(
        array $metadata,
        #[SensitiveParameter]
        string $operationToken,
        LocalExecutorTransportOptions $transportOptions,
        array $commandOptions,
    ): array {
        $optionNames = $transportOptions->redactedCommandOptionNames();
        $redacted = [];

        foreach ($metadata as $key => $value) {
            if (in_array($key, $optionNames, true)) {
                $redacted[$key] = self::REDACTED_VALUE;

                continue;
            }

            $redacted[$key] = $this->redactExceptionMetadataValue(
                value: $value,
                operationToken: $operationToken,
                transportOptions: $transportOptions,
                commandOptions: $commandOptions,
            );
        }

        return $redacted;
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     */
    private function redactExceptionMetadataValue(
        mixed $value,
        #[SensitiveParameter]
        string $operationToken,
        LocalExecutorTransportOptions $transportOptions,
        array $commandOptions,
    ): mixed {
        if (is_string($value)) {
            return $this->redactExceptionText($value, $operationToken, $transportOptions, $commandOptions);
        }

        if (is_array($value)) {
            return $this->redactExceptionMetadata($value, $operationToken, $transportOptions, $commandOptions);
        }

        if (is_bool($value) || is_float($value) || is_int($value) || $value === null) {
            return $value;
        }

        return self::REDACTED_VALUE;
    }

    /**
     * @param  array<int|string, mixed>  $commandOptions
     * @param  list<string>  $optionNames
     * @return list<string>
     */
    private function redactedCommandOptionValues(array $commandOptions, array $optionNames): array
    {
        $values = [];

        foreach ($optionNames as $optionName) {
            $optionValue = $commandOptions[$optionName] ?? null;

            if (! is_string($optionValue) || $optionValue === '') {
                continue;
            }

            if (! in_array($optionValue, $values, true)) {
                $values[] = $optionValue;
            }
        }

        return $values;
    }

    /**
     * @param  list<string>  $optionNames
     */
    private function redactNamedCommandOptions(string $value, array $optionNames): string
    {
        $redacted = $value;

        foreach ($optionNames as $optionName) {
            $redacted =
                preg_replace(
                    '/--'.preg_quote($optionName, '/').'\s*(?:=\s*|\s+)(?:"[^"]*"|\'[^\']*\'|\S+)/',
                    "--{$optionName}=".self::REDACTED_VALUE,
                    $redacted,
                ) ?? $redacted;
        }

        return $redacted;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function rawExceptionMetadata(Throwable $throwable): array
    {
        $reflection = new \ReflectionObject($throwable);

        if (! $reflection->hasProperty('meta')) {
            return [];
        }

        $property = $reflection->getProperty('meta');

        if (! $property->isPublic()) {
            return [];
        }

        $metadata = $property->getValue($throwable);

        return is_array($metadata) ? $metadata : [];
    }

    private function truncate(string $value): string
    {
        if (strlen($value) <= self::OUTPUT_SUMMARY_BYTES) {
            return $value;
        }

        return substr($value, 0, self::OUTPUT_SUMMARY_BYTES).self::TRUNCATED_SUFFIX;
    }
}
