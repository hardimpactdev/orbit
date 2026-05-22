<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

final class AppWorkerController implements Loggable
{
    private ?App $activitySubject = null;

    private string $currentAction = 'show';

    #[RequiresPermission('app:read', servingNode: ServingNode::AppOwning)]
    public function show(string $app): JsonResponse
    {
        return $this->dispatch('show', $app);
    }

    #[RequiresPermission('app:worker', servingNode: ServingNode::AppOwning)]
    public function enable(string $app): JsonResponse
    {
        return $this->dispatch('enable', $app);
    }

    #[RequiresPermission('app:worker', servingNode: ServingNode::AppOwning)]
    public function disable(string $app): JsonResponse
    {
        return $this->dispatch('disable', $app);
    }

    private function dispatch(string $action, string $app): JsonResponse
    {
        $this->currentAction = $action;
        $this->activitySubject = $this->resolveApp($app);

        $exitCode = Artisan::call('app:worker', [
            'action' => $action,
            'app' => $app,
            '--json' => true,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $status = $exitCode === 0 ? 200 : $this->errorStatus($payload);

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function errorStatus(array $payload): int
    {
        $code = is_array($payload['error'] ?? null) && is_string($payload['error']['code'] ?? null)
            ? $payload['error']['code']
            : null;

        return match ($code) {
            'app.not_found' => 404,
            null => 500,
            default => 422,
        };
    }

    private function resolveApp(string $selector): ?App
    {
        $baseQuery = App::query()->with('node');

        $nameMatch = (clone $baseQuery)
            ->where('name', $selector)
            ->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        return $baseQuery
            ->where('domain', $selector)
            ->first();
    }

    public function effect(): ActivityLogType
    {
        return $this->currentAction === 'show' ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'enable' => 'api:POST /apps/{app}/worker/enable',
            'disable' => 'api:POST /apps/{app}/worker/disable',
            default => 'api:GET /apps/{app}/worker',
        };
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function activityLogSubject(): ?Model
    {
        return $this->subject();
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'action' => $this->currentAction,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function activityLogProperties(): array
    {
        return $this->properties();
    }

    public function description(): ?string
    {
        return null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }
}
