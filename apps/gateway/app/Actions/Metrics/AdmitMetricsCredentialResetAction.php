<?php

declare(strict_types=1);

namespace App\Actions\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AdmitMetricsCredentialResetAction
{
    public function execute(): Operation
    {
        if (($existing = app(OperationIdempotency::class)->replay('metrics.credentials_reset')) !== null) {
            return $existing;
        }

        return DB::transaction(function (): Operation {
            $assignments = NodeRole::query()->where('role', RoleName::Metrics)->where('status', 'active')->with('node')->get();
            if ($assignments->count() !== 1) {
                throw new ResourceOperationException('metrics.assignment_missing', 'Credential reset requires one active Metrics assignment.', 409);
            }
            $node = $assignments->sole()->node;
            Node::query()->whereKey($node->id)->lockForUpdate()->firstOrFail();
            if (($existing = app(OperationIdempotency::class)->replay('metrics.credentials_reset')) !== null) {
                return $existing;
            }
            if ($node->status->value !== 'active' || $node->platform !== 'linux') {
                throw new ResourceOperationException('metrics.node_inactive', 'Reset requires its active Linux Metrics Node.', 409);
            }
            app(MetricsGatewayResolver::class)->resolve();
            $ssh = app(SwampSshExecutionInput::class)->forNode($node);
            $settings = app(SettingRepository::class);
            $scope = new SettingScope(SettingScopeType::Node, $node->id);
            $active = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
            if (! is_string($active) || $active === '') {
                throw new ResourceOperationException('metrics.credentials_missing', 'The stored Grafana credential is missing.', 409);
            }
            $pending = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
            if (! is_string($pending) || $pending === '') {
                $pending = Str::random(32);
                $settings->put($scope, NativeMetricsCredentialManager::PendingPasswordKey, $pending, SettingValueProtection::Secret);
            }

            return app(StartOperationAction::class)->execute('metrics.credentials_reset', 'metrics', $node->id, $node->id,
                ['ssh_execution' => $ssh, 'address' => $node->wireguard_ip, 'pending_fingerprint' => hash('sha256', $pending)],
                'operator', request()->attributes->getString('orbit.request_id'));
        });
    }
}
