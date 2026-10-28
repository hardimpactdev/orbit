<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Integrations\IntegrationIntent;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;

/** Restores only the recorded hostname intent after a proven pre-publication refusal. */
final readonly class AiRouterTakeoverRestoreCommand implements OperationGatewayCommand
{
    public function __construct(private CaddySiteCertificates $certificates) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => (object) []];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $takeover = IntegrationIntent::data($operation)['takeover'] ?? null;
        $publication = Operation::query()->where('parent_operation_id', $operation->id)->where('child_key', 'collector-publication')->first();
        $refused = $operation->steps()->where('status', 'failed')->exists()
            || Operation::query()->where('parent_operation_id', $operation->id)->where('status', OperationStatus::Failed)->exists();
        if (! is_array($takeover) || ! $refused || $publication?->status === OperationStatus::Succeeded) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The collector has no refused takeover to restore.', 409);
        }
        DB::transaction(function () use ($operation, $takeover): void {
            $route = Route::query()->whereKey($takeover['id'])->where('node_id', $operation->node_id)
                ->where('domain', AiRouterHostname::Value)->lockForUpdate()->first();
            if (! $route instanceof Route || ! in_array($route->status->value, [RouteStatus::Retiring->value, $takeover['status']], true)) {
                throw new ResourceOperationException('ai_router.takeover_owner_changed', 'The takeover Route changed before restoration.', 409);
            }
            $route->update(['status' => $takeover['status'], 'sites_published' => $takeover['sites_published']]);
            $this->certificates->forget(ValidatedData::integer($operation->node_id), AiRouterCaddySiteSource::CertificateSlug);
        });

        return ['restored' => true];
    }
}
