<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use LogicException;

/** Bounded, redacted public results; no execution or child contract. */
final readonly class OperationResult
{
    public function __construct(private OperationKinds $kinds, private OperationSecrets $secrets) {}

    /** @param array<string, mixed> $result
     * @param  list<string>  $knownValues
     * @return array<string, mixed>
     */
    public function result(Operation $operation, #[\SensitiveParameter] array $result, #[\SensitiveParameter] array $knownValues = []): array
    {
        $definition = $this->kinds->definition($operation->kind);
        if (! is_array($definition)) {
            throw new LogicException('A Swamp kind requires declared result fields.');
        }
        $fields = $definition['result_fields'];
        $safe = OperationResultFields::filter($fields, $result);
        $references = OperationSecretOutputs::references($fields, $safe);
        $safe = ValidatedData::object($this->secrets->redact($safe, $knownValues));
        foreach ($references as $path => $reference) {
            Arr::set($safe, $path, $reference);
        }

        return OperationResultFields::filter($fields, $safe);
    }
}
