<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Private record transport only; Swamp owns every operational action. */
final readonly class DirectOperationPrivateMaterial
{
    public function __construct(private DirectOperation $direct, private OperationSecrets $sources, private OperationSecretOutputs $outputs) {}

    /** @return array<string,mixed> */
    public function read(Operation $operation): array
    {
        $declaration = $this->declaration($operation, 'private_material');
        $input = [];
        foreach ($declaration as $parameter => $source) {
            Arr::set($input, $parameter, Arr::get($operation->input, $parameter));
        }

        return $this->sources->hydrate($operation, '', $input, $declaration)['input'];
    }

    public function artifact(Operation $operation, string $field): OperationArtifact
    {
        $declaration = $this->declaration($operation, 'private_material');
        $name = $declaration[$field] ?? null;
        if ($name === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Only a declared artifact field is available.', 422);
        }
        $source = $this->sources->source($name);
        if (! $source instanceof OperationArtifactSource) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The field is not an artifact source.', 422);
        }
        $references = $this->sources->references($operation, [$field => Arr::get($operation->input, $field)], [$field => $name]);

        return $source->artifact($operation, $references[$field]['id']);
    }

    /** @param array<string,mixed> $values
     * @return array<string,array{'$secret':string,id:int}>
     */
    public function write(Operation $operation, #[\SensitiveParameter] array $values): array
    {
        return DB::transaction(function () use ($operation, $values): array {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $declaration = $this->declaration($operation, 'secret_outputs');
            if ($values === [] || array_diff(array_keys($values), array_keys($declaration)) !== [] || strlen(json_encode($values, JSON_THROW_ON_ERROR)) > OperationResultFields::MaximumResultBytes) {
                throw new ResourceOperationException('operation.execution_forbidden', 'Only declared output fields are accepted.', 422);
            }
            $stored = [];
            foreach ($values as $field => $value) {
                $stored[$field] = $this->outputs->write($operation, $declaration[$field], $value);
            }

            return $stored;
        });
    }

    /** @return array<string,string> */
    private function declaration(Operation $operation, string $field): array
    {
        $operation->refresh();
        $this->direct->projection($operation);
        if ($operation->status->terminal()) {
            throw new ResourceOperationException('operation.finished', 'The Operation has ended.', 409);
        }
        $definition = $this->direct->definition($operation->kind);
        $map = $definition[$field] ?? [];
        if ($map === []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The kind declares no private transport.', 422);
        }

        return ValidatedData::stringMap($map);
    }
}
