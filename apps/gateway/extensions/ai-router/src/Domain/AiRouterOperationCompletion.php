<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;

final readonly class AiRouterOperationCompletion implements OperationCompletion
{
    public function __construct(private CaddySiteCertificates $certificates) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status !== OperationStatus::Succeeded) {
            $takeover = $operation->input['takeover'] ?? null;
            if (is_array($takeover)) {
                Route::query()->whereKey($takeover['id'])->update(['status' => $takeover['status'], 'sites_published' => $takeover['sites_published']]);
            }

            return;
        }
        if (($operation->input['cutover'] ?? false) === true) {
            DB::table('ai_router_cutovers')->where('node_id', $operation->node_id)->delete();
        }
        if ($operation->kind === 'ai_router.teardown') {
            $this->certificates->forget($operation->node_id, AiRouterCaddySiteSource::CertificateSlug);
        } else {
            AiRouterProcessSource::query()->where('process_id', $operation->input['process_id'])
                ->update(['source_revision' => $operation->input['source_revision'], 'activation_pending' => false]);
        }
    }
}
