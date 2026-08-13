<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalExecutorTransportOptions
{
    private const string OPERATION_ID_METADATA_KEY = 'ORBIT_OPERATION_ID';

    private const string BIND_APPLICATION_KEY_OPTION = 'bind_application_key';

    private const string BIND_INPUT_OPTION = 'bind_input';

    private const string COMMAND_OPTION_KEY_PATTERN = '/\A[a-z][a-z0-9-]*\z/';

    /**
     * @param  array<string, mixed>  $values
     */
    private function __construct(
        private array $values,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function operationId(): string
    {
        $metadata = $this->values['metadata'] ?? [];
        $operationId = is_array($metadata) ? $metadata[self::OPERATION_ID_METADATA_KEY] ?? null : null;

        if (is_string($operationId) && trim($operationId) !== '') {
            return $operationId;
        }

        return (string) Str::uuid();
    }

    public function timeoutSeconds(): int
    {
        if (! array_key_exists('timeout', $this->values)) {
            return 30;
        }

        if (is_int($this->values['timeout']) && $this->values['timeout'] > 0) {
            return $this->values['timeout'];
        }

        throw new RuntimeException('timeout must be a positive integer.');
    }

    public function streamTimeoutSeconds(): int
    {
        if (! array_key_exists('timeout', $this->values)) {
            return 0;
        }

        if (is_int($this->values['timeout']) && $this->values['timeout'] >= 0) {
            return $this->values['timeout'];
        }

        throw new RuntimeException('stream timeout must be a non-negative integer.');
    }

    public function input(): ?string
    {
        if (! array_key_exists('input', $this->values)) {
            return null;
        }

        if (is_string($this->values['input'])) {
            return $this->values['input'];
        }

        throw new RuntimeException('input must be a string.');
    }

    public function boundInput(): ?string
    {
        return $this->shouldBindInput() ? $this->input() : null;
    }

    public function cwd(): ?string
    {
        if (! array_key_exists('cwd', $this->values)) {
            return null;
        }

        if (is_string($this->values['cwd'])) {
            return $this->values['cwd'];
        }

        throw new RuntimeException('cwd must be a string.');
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        if (! array_key_exists('environment', $this->values) || $this->values['environment'] === []) {
            return [];
        }

        if (! is_array($this->values['environment'])) {
            throw new RuntimeException('environment must be an array of string values.');
        }

        $environment = $this->values['environment'];
        $resolved = [];

        array_walk($environment, static function (mixed $value, int|string $key) use (&$resolved): void {
            if (! is_string($key) || ! is_string($value)) {
                throw new RuntimeException('environment must be an array of string values.');
            }

            $resolved[$key] = $value;
        });

        return $resolved;
    }

    public function shouldBindApplicationKey(): bool
    {
        return $this->booleanOption(self::BIND_APPLICATION_KEY_OPTION, default: true);
    }

    public function forceRemoteHost(): bool
    {
        return ($this->values['force_remote_host'] ?? false) === true;
    }

    public function redactStdout(): bool
    {
        return ($this->values['redact_stdout'] ?? false) === true;
    }

    public function redactStderr(): bool
    {
        return ($this->values['redact_stderr'] ?? false) === true;
    }

    /**
     * @return list<string>
     */
    public function redactedCommandOptionNames(): array
    {
        $optionNames = $this->values['redact_command_options'] ?? [];

        if ($optionNames === []) {
            return [];
        }

        if (! is_array($optionNames) || ! array_is_list($optionNames)) {
            throw new RuntimeException('redact_command_options must be a list of command option names.');
        }

        $redacted = [];

        foreach ($optionNames as $optionName) {
            if (! is_string($optionName) || preg_match(self::COMMAND_OPTION_KEY_PATTERN, $optionName) !== 1) {
                throw new RuntimeException('redact_command_options must be a list of command option names.');
            }

            if (! in_array($optionName, $redacted, true)) {
                $redacted[] = $optionName;
            }
        }

        return $redacted;
    }

    /**
     * @param  array<string, string>  $environment
     * @return array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     environment: array<string, string>,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     *     force_remote_host?: bool,
     * }
     */
    public function dispatchOptions(array $environment): array
    {
        /**
         * @var array{
         *     cwd?: string,
         *     timeout?: int,
         *     input?: string,
         *     throw?: bool,
         *     environment?: array<string, string>,
         *     metadata?: array<string, string>,
         *     strict?: bool,
         *     redact_stdout?: bool,
         *     redact_stderr?: bool,
         *     redact_command_options?: list<string>,
         *     bind_application_key?: bool,
         *     bind_input?: bool,
         *     force_remote_host?: bool,
         * } $options
         */
        $options = $this->values;

        unset(
            $options['redact_stdout'],
            $options['redact_stderr'],
            $options['redact_command_options'],
            $options[self::BIND_APPLICATION_KEY_OPTION],
            $options[self::BIND_INPUT_OPTION],
        );

        $options['environment'] = $environment;

        return $options;
    }

    private function shouldBindInput(): bool
    {
        return $this->booleanOption(self::BIND_INPUT_OPTION, default: true);
    }

    private function booleanOption(string $key, bool $default): bool
    {
        if (! array_key_exists($key, $this->values)) {
            return $default;
        }

        if (is_bool($this->values[$key])) {
            return $this->values[$key];
        }

        throw new RuntimeException("{$key} must be a boolean.");
    }
}
