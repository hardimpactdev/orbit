<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use App\Domain\Operations\DirectOperationResultHook;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationResultFields;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Arr;

final readonly class VaultDirectResult implements DirectOperationResultHook
{
    public function input(Operation $operation): array
    {
        return ['action' => substr($operation->kind, 6)];
    }

    public function validate(Operation $operation, array $result, OperationStatus $status): array
    {
        $known = $operation->status->terminal() ? [] : app(VaultOperationSecrets::class)->resolve($operation, $operation->target_id, '', '')->strings();
        if ($this->publicResult($result, $known) !== $result) {
            throw new ResourceOperationException('operation.result_invalid', 'Only safe Vault metadata is accepted.', 422);
        }

        return $result;
    }

    /** Public transport metadata only; no native execution proof or business outcome checks.
     *
     * @param  array<string,mixed>  $result
     * @param  list<string>  $private
     * @return array<string,mixed>
     */
    public function publicResult(array $result, #[\SensitiveParameter] array $private = []): array
    {
        $refuse = static function (): never {
            throw new ResourceOperationException('operation.result_invalid', 'Only bounded public Vault metadata is accepted.', 422);
        };
        $text = static function (mixed $value) use ($private, $refuse): void {
            if (! is_string($value) || strlen($value) > OperationResultFields::MaximumStringBytes
                || array_any($private, static fn (string $secret): bool => $secret !== '' && str_contains($value, $secret))) {
                $refuse();
            }
        };
        $safe = Arr::only($result, ['outcome', 'error_code']);
        foreach ($safe as $value) {
            $text($value);
        }
        if (isset($result['data'])) {
            if (! is_array($result['data'])) {
                $refuse();
            }
            $data = Arr::only($result['data'], ['backend', 'email', 'organization_id', 'organization_name', 'collection_id', 'collection_name',
                'sources', 'cipher_ids', 'revisions', 'fingerprints']);
            foreach ($data as $field => $value) {
                if (in_array($field, ['sources', 'cipher_ids', 'revisions', 'fingerprints'], true)) {
                    if (! is_array($value) || ! array_is_list($value) || count($value) > OperationResultFields::MaximumItems) {
                        $refuse();
                    }
                    foreach ($value as $entry) {
                        $text($entry);
                    }
                } else {
                    $text($value);
                }
            }
            $safe['data'] = $data;
        }
        if (strlen(json_encode($safe, JSON_THROW_ON_ERROR)) > OperationResultFields::MaximumResultBytes) {
            $refuse();
        }

        return $safe;
    }

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        app(VaultOperationCompletion::class)->complete($operation, $outcome);
    }
}
