<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects the shared native terminal reader's public result; performs no host work. */
final readonly class NativeCaddyPublicationProjection implements DirectOperationProjection
{
    public const string EndCheck = 'Check the publication';

    public function input(Operation $operation): array
    {
        return ['intent' => app(NativeCaddyPublicationInput::class)->forOperation($operation), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $result = [];
        if (($data['status'] ?? null) === 'succeeded') {
            $native = ValidatedData::object($data['result'] ?? []);
            $caddy = ValidatedData::object($native['caddy'] ?? []);
            $version = $caddy['version'] ?? null;
            if (NativeRunResult::stepStatus($native, self::EndCheck) !== 'succeeded'
                || ! is_string($version) || preg_match('/^[a-f0-9]{32}$/', $version) !== 1) {
                throw new ResourceOperationException('operation.invalid_result', 'Caddy publication requires its end check and captured content version.', 422);
            }
            $result = ['ready' => true, 'result' => 'published', 'version' => $version];
        }

        return app(DirectOperation::class)->project($operation, $data, $result);
    }
}
