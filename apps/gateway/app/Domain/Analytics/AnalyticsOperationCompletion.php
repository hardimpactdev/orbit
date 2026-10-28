<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

final readonly class AnalyticsOperationCompletion implements OperationCompletion
{
    public function __construct(private AnalyticsRoleSettingsRepository $settings, private AnalyticsSecretManager $secrets, private CaddySiteCertificates $certificates) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($operation->kind === 'analytics.clickhouse_converge') {
            return;
        }
        $node = Node::query()->findOrFail($operation->node_id);
        $assignment = NodeRole::query()->whereKey(ValidatedData::integer($operation->input['assignment_id']))->first();
        if ($outcome->status !== OperationStatus::Succeeded) {
            if (is_string($operation->input['previous_version'] ?? null)) {
                $this->settings->storeVersion($node, $operation->input['previous_version']);
            }
            $assignment?->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'analytics', 'error_code' => $outcome->errorCode]);

            return;
        }
        if ($operation->kind === 'analytics.disable') {
            if ($operation->input['unreachable']) {
                Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', PlausibleProcess::NAME)->delete();
            }
            $this->settings->purge($node);
            $this->secrets->purge($node);
            $this->certificates->forget($node->id, AnalyticsCaddySiteSource::CertificateSlug);
        } else {
            $assignment?->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
        }
    }
}
