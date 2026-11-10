<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Stored serving state follows the native terminal observation only. */
final readonly class NativeRouteConvergenceProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** Each chart step's error code. A step fails with its own exit code; its stderr carries the reason (rule 14). */
    public const array StepCodes = [
        'Make a CSR on the Node' => 'app-dev.certificate_request_failed',
        'Sign the CSR' => 'app-dev.certificate_sign_failed',
        'Allow the Router on LAN' => 'route.lan_firewall_failed',
        'Install cert, publish site' => 'route.publish_failed',
        'Probe the site over TLS' => 'route.probe_failed',
        'Open the Ingress edge' => 'route.ingress_firewall_failed',
        'Publish private DNS' => 'private_dns.publication_failed',
    ];

    public function __construct(private DirectOperation $direct) {}

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['route_create'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $wasTerminal = $operation->status->terminal();
            $step = NativeFailedStep::label($data, array_keys(self::StepCodes));
            if ($data['status'] === 'failed') {
                $data['error_code'] = $step === null ? ($data['error_code'] ?? 'route.create_failed') : self::StepCodes[$step];
            }
            $intent = ValidatedData::object($operation->input['route_lifecycle']);
            $result = match ($data['status']) {
                'succeeded' => ['ready' => true, 'route_id' => $operation->target_id],
                'failed' => ['error_code' => $data['error_code'], 'failed_step' => $step],
                default => ValidatedData::object($data['result'] ?? []),
            };
            $operation = $this->direct->project($operation, $data, $result);
            if ($wasTerminal || ! $operation->status->terminal()) {
                return $operation;
            }
            if ($intent['effectful'] && $operation->status === OperationStatus::Succeeded) {
                Route::query()->whereKey($operation->target_id)->update(['status' => RouteStatus::Active, 'sites_published' => true,
                    'replacement_step' => $intent['public_activated'] ? RouteReplacementStep::IngressFirewall : null,
                    'failed_step' => null, 'error_code' => null]);
                app(DevelopmentRoutePublication::class)->settle(Route::query()->findOrFail($operation->target_id));
            } elseif ($intent['effectful'] && $operation->status === OperationStatus::Failed) {
                Route::query()->whereKey($operation->target_id)->update(['failed_step' => $step ?? 'native-create', 'error_code' => $operation->error_code]);
            }

            return $operation;
        });
    }
}
