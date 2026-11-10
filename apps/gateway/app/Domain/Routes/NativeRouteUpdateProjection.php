<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Domain\Analytics\AnalyticsRoutePlan;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\NativeFailedStep;
use App\Domain\Operations\NativeOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** The stored Route follows Swamp's run data: success, or a failure after the cutover, selects the new state. */
final readonly class NativeRouteUpdateProjection implements DirectOperationProjection, NativeOperationProjection
{
    /** Each chart step's error code. A step fails with its own exit code; its stderr carries the reason (rule 14). */
    public const array StepCodes = [
        'Make a CSR on the Node' => 'app-dev.certificate_request_failed',
        'Sign the CSR' => 'app-dev.certificate_sign_failed',
        'Install cert, publish site' => 'route.publish_failed',
        'Write the new APP_URL' => 'route.app_url_failed',
        'Probe the site over TLS' => 'route.probe_failed',
        'Open or close the edge' => 'route.ingress_firewall_failed',
        'Move private DNS' => 'private_dns.publication_failed',
        'Wait the DNS grace' => 'route.dns_grace_failed',
        'Withdraw the old site' => 'route.withdraw_failed',
    ];

    /** From here the new name serves, so a failure recovers forward. */
    private const array AfterCutover = ['Wait the DNS grace', 'Withdraw the old site'];

    public function native(Operation $operation): bool
    {
        return true;
    }

    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['route_update'])['plan'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $wasTerminal = $operation->status->terminal();
            $step = NativeFailedStep::label($data, array_keys(self::StepCodes));
            if ($data['status'] === 'failed') {
                $data['error_code'] = $step === null ? ($data['error_code'] ?? 'route.update_failed') : self::StepCodes[$step];
            }
            $result = match ($data['status']) {
                'succeeded' => ['ready' => true, 'route_id' => ValidatedData::integer(ValidatedData::object($operation->input['route_update'])['result_route_id'])],
                'failed' => ['error_code' => $data['error_code'], 'failed_step' => $step],
                default => ValidatedData::object($data['result'] ?? []),
            };
            $operation = app(DirectOperation::class)->project($operation, $data, $result);
            if ($wasTerminal || ! $operation->status->terminal()) {
                return $operation;
            }
            $this->settle($operation, $step);

            return $operation;
        });
    }

    /**
     * The one Route state change of an update. Admission calls it for a change that no Node sees (R1).
     * The caller holds the transaction.
     */
    public function settle(Operation $operation, ?string $failedStep = null): void
    {
        $intent = ValidatedData::object($operation->input['route_update']);
        $source = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($intent['route_id']));
        $selected = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($intent['result_route_id']));
        $renamed = $source->id !== $selected->id;
        $cutover = $operation->status === OperationStatus::Failed && in_array($failedStep, self::AfterCutover, true);
        if ($operation->status === OperationStatus::Succeeded || $cutover) {
            if ($renamed) {
                app(RouteMutationState::class)->cutover($source, $selected);
            }
            $selected->update([...ValidatedData::object($intent['selected']),
                ...($intent['effectful'] ? ['status' => RouteStatus::Active, 'sites_published' => true,
                    'replacement_step' => ($intent['public_activated'] ?? false) ? RouteReplacementStep::IngressFirewall : null,
                    'failed_step' => null, 'error_code' => null] : []),
                ...($renamed ? ['replaces_route_id' => null,
                    ...(! $intent['effectful'] ? ['status' => RouteStatus::Pending, 'sites_published' => false, 'replacement_step' => null] : [])] : [])]);
            if ($renamed && $cutover) {
                // The new name serves; the old Route stays retiring until a rerun withdraws its site.
                $source->update(['status' => RouteStatus::Retiring, 'failed_step' => $failedStep, 'error_code' => $operation->error_code]);
            } elseif ($renamed) {
                $source->update(['replaced_by_route_id' => null]);
                $source->delete();
            } elseif ($cutover) {
                $selected->update(['failed_step' => $failedStep, 'error_code' => $operation->error_code]);
            }
            if ($operation->status === OperationStatus::Succeeded) {
                app(DevelopmentRoutePublication::class)->settle($selected);
            }
            foreach (['certificates_ready', 'public_verified', 'placement_cleanup', 'complete'] as $stage) {
                app(AnalyticsRoutePlan::class)->checkpointUpdate(RouteExecutionProof::objects($intent['analytics_changes'] ?? []), $stage);
            }
        } elseif ($operation->status === OperationStatus::Failed && $intent['effectful']) {
            // Before the cutover the old Route still serves; the replacement's new site serves no name.
            ($renamed ? $selected : $source)->update([...($renamed ? ['status' => RouteStatus::Failed] : []),
                'failed_step' => $failedStep ?? 'native-update', 'error_code' => $operation->error_code]);
        }
    }
}
