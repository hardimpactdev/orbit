<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Operations\NativeOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The shared terminal reader owns delivery; this class changes only stored intent. */
final readonly class AnalyticsDisableProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** Each chart step's error code. A step fails with its own exit code; its stderr carries the reason (rule 14). */
    public const array StepCodes = [
        'Remove private DNS' => 'private_dns.publication_failed',
        'Withdraw sites, certs' => 'route.withdraw_failed',
        'Check the hosts are gone' => 'route.removal_check_failed',
    ];

    public function __construct(private DirectOperation $direct, private AnalyticsTrackingIntent $tracking) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['native_disable']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            if (! $operation->status->terminal() && ($data['status'] ?? null) === 'succeeded') {
                $this->tracking->complete($operation);
            }
            $step = NativeFailedStep::label($data, array_keys(self::StepCodes));
            if (($data['status'] ?? null) === 'failed') {
                $data['error_code'] = $step === null ? ($data['error_code'] ?? 'analytics.disable_failed') : self::StepCodes[$step];
            }
            $site = ValidatedData::object($operation->input['native_disable'])['site'] ?? null;
            $result = match ($data['status'] ?? null) {
                'succeeded' => ['ready' => true, 'enabled' => false, 'detached' => true,
                    'domain' => is_array($site) ? ($site['domain'] ?? null) : null, 'timezone' => is_array($site) ? ($site['timezone'] ?? null) : null],
                'failed' => ['ready' => false, 'error_code' => $data['error_code'], 'failed_step' => $step],
                default => [],
            };

            return $this->direct->project($operation, $data, $result);
        });
    }
}
