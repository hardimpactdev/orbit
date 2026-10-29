<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Logs\LogRedactor;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Operation;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Arr;
use LogicException;

/**
 * Hydrates only declared, target-owned references. Holds no values between requests.
 *
 * @phpstan-import-type RunInput from SwampOperationExecutor
 */
final readonly class OperationSecrets
{
    /** @param iterable<OperationSecretSource> $sources */
    public function __construct(#[Tag(OperationSecretSource::class)] private iterable $sources, private LogRedactor $redactor, private CommandActivityInputSanitizer $sanitizer) {}

    /** @param array<string, mixed> $input
     * @param  array<string, string>  $declarations
     * @return array{input: array<string, mixed>, values: list<string>}
     */
    public function hydrate(Operation $operation, string $key, #[\SensitiveParameter] array $input, array $declarations): array
    {
        $references = $this->references($operation, $input, $declarations);
        $values = [];
        foreach ($references as $parameter => $reference) {
            try {
                $value = $this->source($reference['$secret'])->resolve($operation, $reference['id'], $key, $parameter);
            } catch (ResourceOperationException $exception) {
                throw self::refusal($exception);
            } catch (\Throwable) {
                $this->refuse();
            }
            $redactions = $value->strings();
            if ($redactions === [] && $value->requiresRedaction()) {
                $this->refuse();
            }
            Arr::set($input, $parameter, $value->value);
            $values = [...$values, ...$redactions];
        }

        return ['input' => $input, 'values' => $values];
    }

    /**
     * Read only a declared existing connection credential, never payload hydration or minting.
     *
     * @param  RunInput  $run
     */
    public function connection(Operation $operation, array $run, string $parameter): OperationSecretValue
    {
        $spec = app(OperationExecutionContract::class)->authorizeRun($operation, $run);
        $name = $spec['secrets'][$parameter] ?? null;
        if ($name === null) {
            $this->refuse();
        }
        $input = [];
        Arr::set($input, $parameter, Arr::get($run['input'], $parameter));
        $reference = $this->references($operation, $input, [$parameter => $name])[$parameter];
        $source = $this->source($name);
        if (! $source instanceof OperationConnectionSecretSource) {
            $this->refuse();
        }
        try {
            $value = $source->resolveConnection($operation, $reference['id']);
            if ($value->requiresRedaction() && $value->strings() === []) {
                $this->refuse();
            }

            return $value;
        } catch (ResourceOperationException $exception) {
            throw self::refusal($exception);
        } catch (\Throwable) {
            $this->refuse();
        }
    }

    /** @param array<string, mixed> $input
     * @param  array<string, string>  $declarations
     * @return array<string, array{'$secret': string, id: int}>
     */
    public function references(Operation $operation, array $input, array $declarations): array
    {
        $references = [];
        foreach ($declarations as $parameter => $name) {
            $reference = Arr::get($input, $parameter);
            if (! is_array($reference) || count($reference) !== 2 || ($reference['$secret'] ?? null) !== $name
                || ! is_int($reference['id'] ?? null) || $reference['id'] < 1) {
                $this->refuse();
            }
            $source = $this->source($name);
            if (! in_array($operation->target_type, $source->targetTypes(), true)) {
                $this->refuse();
            }
            $ownerId = $source->ownerId($operation);
            if ($ownerId === null || $ownerId !== $reference['id']) {
                $this->refuse();
            }
            $references[$parameter] = ['$secret' => $name, 'id' => $reference['id']];
            Arr::forget($input, $parameter);
        }
        if ($this->containsReference($input)) {
            $this->refuse();
        }

        return $references;
    }

    public function source(string $name): OperationSecretSource
    {
        $found = null;
        foreach ($this->sources as $source) {
            if ($source->name() === $name) {
                if ($found !== null) {
                    throw new LogicException("Duplicate Operation secret source [{$name}].");
                }
                $found = $source;
            }
        }
        if ($found === null) {
            $this->refuse();
        }

        return $found;
    }

    /** @param list<string> $values */
    public function redact(mixed $value, #[\SensitiveParameter] array $values): mixed
    {
        return $this->redactValues(is_array($value) ? $this->sanitizer->sanitizeProperties($value) : $value, $values);
    }

    /** @param list<string> $values */
    private function redactValues(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] array $values): mixed
    {
        if (is_scalar($value) && ! is_string($value) && in_array((string) $value, $values, true)) {
            return '[REDACTED]';
        }
        if (is_string($value)) {
            usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

            return $this->redactor->redact($value, $values);
        }
        if (is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $safe[is_string($key) ? $this->redactor->redact($key, $values) : $key] = $this->redactValues($item, $values);
            }

            return $safe;
        }

        return $value;
    }

    private function containsReference(mixed $value): bool
    {
        return is_array($value) && (array_key_exists('$secret', $value) || array_any($value, $this->containsReference(...)));
    }

    public static function refusal(ResourceOperationException $exception): ResourceOperationException
    {
        $code = preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $exception->errorCode) === 1 ? $exception->errorCode : 'operation.execution_forbidden';

        return new ResourceOperationException($code, 'The named operation refused this request.', min(599, max(400, $exception->status)));
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The protected input does not belong to the Operation target.', 422);
    }
}
