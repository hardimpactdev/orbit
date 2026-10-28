<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Integrations\IntegrationIntent;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Orbit\Extensions\AiRouter\Models\AiRouterProcessSource;

final readonly class AiRouterOperationCompletion implements OperationCompletion
{
    public function __construct(private CaddySiteCertificates $certificates) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if (($operation->input['noop'] ?? false) === true) {
            return;
        }
        if ($outcome->status !== OperationStatus::Succeeded) {

            return;
        }
        if ((IntegrationIntent::data($operation)['cutover'] ?? false) === true) {
            DB::table('ai_router_cutovers')->where('node_id', $operation->node_id)->delete();
        }
        if ($operation->kind === 'ai_router.teardown') {
            $this->certificates->forget(ValidatedData::integer($operation->node_id), AiRouterCaddySiteSource::CertificateSlug);
        } else {
            AiRouterProcessSource::query()->where('process_id', IntegrationIntent::data($operation)['process_id'])
                ->update(['source_revision' => IntegrationIntent::data($operation)['source_revision'], 'activation_pending' => false]);
        }
    }
}
