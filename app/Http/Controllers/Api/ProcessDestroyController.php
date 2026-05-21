<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Processes\RemoveProcess;
use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Gateway\GatewayApiException;
use App\Models\App;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('process:remove', servingNode: ServingNode::AppOwning)]
final class ProcessDestroyController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(string $name, Request $request, RemoveProcess $removeProcess): JsonResponse
    {
        $appName = $this->optionalString($request, 'app');

        if ($appName === null) {
            return $this->error('validation_failed', 'An app context is required.', ['field' => 'app'], 422);
        }

        if ($request->boolean('destructive_consent') !== true) {
            return $this->error('validation_failed', 'Use --force to remove this process.', ['field' => 'force'], 422);
        }

        $app = App::query()->with(['node', 'workspaces'])->where('name', $appName)->first();

        if (! $app instanceof App) {
            return $this->error('validation_failed', "App '{$appName}' not found.", ['field' => 'app', 'value' => $appName], 422);
        }

        try {
            $result = $removeProcess->handle($app, $name);
        } catch (GatewayApiException $e) {
            return $this->error($e->errorCode() ?? 'validation_failed', $e->getMessage(), $e->errorMeta(), $e->errorCode() === 'process.not_found' ? 404 : 422);
        }

        $this->activitySubject = $app;

        return response()->json([
            'success' => [
                'data' => $result['data'],
                'meta' => [
                    'warnings' => $result['warnings'],
                ],
            ],
        ]);
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => empty($meta) ? (object) [] : $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Destructive;
    }

    public function type(): string
    {
        return 'api:DELETE /processes/{name}';
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [
            'app' => $this->optionalString(request(), 'app'),
        ];
    }

    public function description(): ?string
    {
        return null;
    }
}
