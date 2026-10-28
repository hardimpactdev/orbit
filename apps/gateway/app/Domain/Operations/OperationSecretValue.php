<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentGitHubToken;
use JsonSerializable;
use SensitiveParameter;

/** Request-memory-only value. Debug and JSON serialization never expose it. */
final readonly class OperationSecretValue implements JsonSerializable
{
    /** @param list<string> $redactions Include individual values when the payload is rendered content. */
    public function __construct(#[SensitiveParameter] public mixed $value, #[SensitiveParameter] public array $redactions = []) {}

    public static function script(#[SensitiveParameter] string $contents, string $admittedSha256): self
    {
        if (! hash_equals(hash('sha256', $contents), $admittedSha256)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The protected script does not match admitted intent.', 422);
        }

        return new self($contents, [$contents]);
    }

    /** Known values with no protected bytes keep their native schema type without a mask. */
    public function requiresRedaction(): bool
    {
        if ($this->value === null || $this->value === '' || $this->value === []) {
            return false;
        }
        if ($this->value instanceof GitReadEnvironment) {
            return ! $this->value->isEmpty() || $this->value->githubToken() !== null;
        }
        if (is_object($this->value) && $this->value::class === \stdClass::class) {
            return get_object_vars($this->value) !== [];
        }

        return true;
    }

    /** @return list<string> */
    public function strings(): array
    {
        $collect = static function (mixed $value) use (&$collect): array {
            if ($value instanceof AgentGitHubToken) {
                return [$value->value, base64_encode('x-access-token:'.$value->value)];
            }
            if ($value instanceof GitReadEnvironment) {
                return $value->githubToken() === null ? [] : [$value->githubToken(), base64_encode('x-access-token:'.$value->githubToken())];
            }
            if (is_scalar($value)) {
                return [(string) $value];
            }
            $strings = [];
            if (is_array($value)) {
                foreach ($value as $item) {
                    $strings = [...$strings, ...$collect($item)];
                }
            }

            return $strings;
        };

        return array_values(array_unique(array_filter([...$this->redactions, ...$collect($this->value)], static fn (string $value): bool => $value !== '')));
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    public function jsonSerialize(): string
    {
        return '[REDACTED]';
    }
}
