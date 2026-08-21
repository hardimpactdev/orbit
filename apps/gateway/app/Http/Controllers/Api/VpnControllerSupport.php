<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Services\Vpn\ActiveVpnNodeUnavailable;
use App\Services\Vpn\FileVpnBackend;
use App\Services\Vpn\VpnBackend;
use App\Services\Vpn\VpnClientManager;
use App\Services\Vpn\VpnFailure;
use App\Services\Vpn\VpnNodeResolver;
use App\Services\Vpn\WgEasyVpnBackend;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

abstract class VpnControllerSupport implements Loggable
{
    protected function manager(): VpnClientManager|VpnFailure
    {
        $fakeBackendPath = config('services.wg_easy.fake_backend_path');

        if (is_string($fakeBackendPath) && $fakeBackendPath !== '') {
            return new VpnClientManager(new FileVpnBackend($fakeBackendPath));
        }

        try {
            app(VpnNodeResolver::class)->activeVpnNode();
        } catch (ActiveVpnNodeUnavailable) {
            return new VpnFailure(
                code: 'vpn_runtime_unavailable',
                message: 'No active VPN role node is available for VPN administration.',
            );
        }

        $backend = app()->bound(VpnBackend::class)
            ? app(VpnBackend::class)
            : WgEasyVpnBackend::fromConfig();

        return new VpnClientManager($backend);
    }

    protected function totp(Request $request): ?string
    {
        $totp = $request->query('totp');

        return is_string($totp) && trim($totp) !== '' ? trim($totp) : null;
    }

    protected function fail(VpnFailure $failure, int $status = 400): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $failure->code,
                'message' => $failure->message,
                'meta' => $failure->meta === [] ? (object) [] : $failure->meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return match (static::class) {
            VpnClientListController::class => ActivityLogType::Read,
            VpnClientRemoveController::class, VpnWebUiChangePasswordController::class => ActivityLogType::Destructive,
            default => ActivityLogType::Write,
        };
    }

    public function type(): string
    {
        return match (static::class) {
            VpnClientListController::class => 'api:GET /vpn/clients',
            VpnClientCreateController::class => 'api:POST /vpn/clients',
            VpnClientEnableController::class => 'api:POST /vpn/clients/{name}/enable',
            VpnClientDisableController::class => 'api:POST /vpn/clients/{name}/disable',
            VpnClientRemoveController::class => 'api:DELETE /vpn/clients/{name}',
            VpnWebUiChangePasswordController::class => 'api:POST /vpn/web-ui/password',
            default => throw new LogicException('Unsupported VPN activity controller ['.static::class.'].'),
        };
    }

    public function subject(): ?Model
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return array_filter(
            [
                'client' => request()->route('name'),
            ],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );
    }

    public function description(): ?string
    {
        return null;
    }
}
