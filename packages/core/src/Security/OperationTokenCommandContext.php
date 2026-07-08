<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class OperationTokenCommandContext
{
    public const string OPERATION_TOKEN_SENTINEL = '__orbit_operation_token__';

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $environment
     */
    private function __construct(
        private array $argv,
        private ?string $cwd,
        private array $environment,
        private ?string $input,
    ) {}

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $environment
     */
    public static function fromTrustedDispatch(
        array $argv,
        ?string $cwd,
        array $environment,
        ?string $input,
    ): self {
        return new self(
            argv: self::normalizeOperationTokenArgument($argv, null),
            cwd: self::nullableString($cwd, 'cwd'),
            environment: self::normalizeEnvironment($environment),
            input: $input,
        );
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, string>  $environment
     */
    public static function fromAgentVerification(
        array $argv,
        ?string $cwd,
        array $environment,
        ?string $input,
        #[SensitiveParameter]
        string $operationToken,
    ): self {
        if (trim($operationToken) === '') {
            throw new InvalidArgumentException('Operation token is required for command context verification.');
        }

        return new self(
            argv: self::normalizeOperationTokenArgument($argv, $operationToken),
            cwd: self::nullableString($cwd, 'cwd'),
            environment: self::normalizeEnvironment($environment),
            input: $input,
        );
    }

    public function hash(): string
    {
        return hash('sha256', $this->canonicalPayload());
    }

    private function canonicalPayload(): string
    {
        $payload = $this->lengthPrefixed('operation-token-context-v1');
        $payload .= pack('N', count($this->argv));

        foreach ($this->argv as $argument) {
            $payload .= $this->lengthPrefixed($argument);
        }

        $payload .= $this->nullableLengthPrefixed($this->cwd);
        $payload .= pack('N', count($this->environment));

        foreach ($this->environment as $key => $value) {
            $payload .= $this->lengthPrefixed($key);
            $payload .= $this->lengthPrefixed($value);
        }

        return $payload.$this->nullableLengthPrefixed($this->input);
    }

    /**
     * @param  array<array-key, mixed>  $argv
     * @return list<string>
     */
    private static function normalizeOperationTokenArgument(
        array $argv,
        #[SensitiveParameter]
        ?string $expectedToken,
    ): array {
        if (! array_is_list($argv)) {
            throw new InvalidArgumentException('Operation token command context argv must be a list.');
        }

        $normalized = [];
        $operationTokenArgumentCount = 0;

        foreach ($argv as $argument) {
            if (! is_string($argument)) {
                throw new InvalidArgumentException('Operation token command context argv must contain strings.');
            }

            self::ensureNoNullByte($argument, 'argv');

            if ($argument === '--operation-token') {
                throw new InvalidArgumentException(
                    'Operation token command context must use --operation-token=<value>.',
                );
            }

            if (! str_starts_with($argument, '--operation-token=')) {
                $normalized[] = $argument;

                continue;
            }

            $operationTokenArgumentCount++;
            $value = substr($argument, strlen('--operation-token='));

            if ($value === '') {
                throw new InvalidArgumentException(
                    'Operation token command context operation token argument is empty.',
                );
            }

            if ($expectedToken !== null && ! hash_equals($expectedToken, $value)) {
                throw new InvalidArgumentException(
                    'Operation token command context operation token argument mismatch.',
                );
            }

            $normalized[] = '--operation-token='.self::OPERATION_TOKEN_SENTINEL;
        }

        if ($operationTokenArgumentCount !== 1) {
            throw new InvalidArgumentException(
                'Operation token command context must contain exactly one operation token argument.',
            );
        }

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $environment
     * @return array<string, string>
     */
    private static function normalizeEnvironment(array $environment): array
    {
        $normalized = [];

        foreach ($environment as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new InvalidArgumentException(
                    'Operation token command context environment must be string keyed strings.',
                );
            }

            self::ensureNoNullByte($key, 'environment key');
            self::ensureNoNullByte($value, 'environment value');

            $normalized[$key] = $value;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private static function nullableString(?string $value, string $field): ?string
    {
        if ($value !== null) {
            self::ensureNoNullByte($value, $field);
        }

        return $value;
    }

    private static function ensureNoNullByte(string $value, string $field): void
    {
        if (! str_contains($value, "\0")) {
            return;
        }

        throw new InvalidArgumentException("Operation token command context {$field} contains a null byte.");
    }

    private function nullableLengthPrefixed(?string $value): string
    {
        if ($value === null) {
            return 'N';
        }

        return 'S'.$this->lengthPrefixed($value);
    }

    private function lengthPrefixed(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}
