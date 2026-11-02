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

/** Native execution uses immutable inputs; only the shared terminal reader projects. */
final readonly class AnalyticsIntentProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** Each chart step's error code. A step fails with its own exit code; its stderr carries the reason (rule 14). */
    public const array StepCodes = [
        'Converge the site' => 'analytics.site_failed',
        'Request the certificate' => 'app-dev.certificate_request_failed',
        'Sign the CSR' => 'app-dev.certificate_sign_failed',
        'Publish Caddy, reload' => 'route.publish_failed',
        'Probe the tracking host' => 'route.probe_failed',
        'Publish private DNS' => 'private_dns.publication_failed',
    ];

    public function __construct(private DirectOperation $direct, private AnalyticsTrackingIntent $tracking) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['native_enable']),
            'execution_secrets' => ValidatedData::object($operation->input['execution_secrets'])];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->refresh();
            if (! $operation->status->terminal() && ($data['status'] ?? null) === 'succeeded') {
                $this->tracking->assertCurrent($operation);
                $this->tracking->publish($operation);
                $this->tracking->activatePublic($operation);
                $this->tracking->cleanupPlacement($operation);
                $this->tracking->complete($operation);
            }
            $step = NativeFailedStep::label($data, array_keys(self::StepCodes));
            if (($data['status'] ?? null) === 'failed') {
                $data['error_code'] = $step === null ? ($data['error_code'] ?? 'analytics.enable_failed') : self::StepCodes[$step];
            }
            // The result comes from the admitted site and Swamp's run status, never from step output.
            $site = ValidatedData::object($operation->input['site']);
            $result = match ($data['status'] ?? null) {
                'succeeded' => ['ready' => true, 'enabled' => true, 'readable' => true, 'domain' => $site['domain'], 'timezone' => $site['timezone']],
                'failed' => ['ready' => false, 'error_code' => $data['error_code'], 'failed_step' => $step],
                default => [],
            };

            return $this->direct->project($operation, $data, $result);
        });
    }
}
