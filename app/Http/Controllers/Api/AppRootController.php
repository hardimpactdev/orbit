<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class AppRootController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(string $app, Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($caller->role === 'app') {
            return $this->error('caller_role_not_allowed', 'This command may only be run from an operator or gateway node.', ['caller_role' => 'app'], 403);
        }

        $targetApp = $this->resolveApp($app);

        if (! $targetApp instanceof App) {
            return $this->error('app.not_found', "Application '{$app}' not found.", ['app' => $app], 404);
        }

        $targetApp->loadMissing('node');

        if (! $targetApp->node instanceof Node || ! $this->callerCanManageApp($caller, $targetApp)) {
            return $this->error('authorization_failed', "This node is not authorized to manage app '{$targetApp->name}'.", ['app' => $targetApp->name], 403);
        }

        $root = $this->optionalString($request, 'root');

        if ($root === null) {
            return $this->error('validation_failed', 'Root is required.', ['field' => 'root'], 422);
        }

        $exitCode = Artisan::call('app:root', [
            'app' => $app,
            'root' => $root,
            '--json' => true,
        ]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);
        $this->activitySubject = App::query()->where('name', $targetApp->name)->first();

        return response()->json($payload, $exitCode === 0 ? 200 : 422);
    }

    private function resolveApp(string $selector): ?App
    {
        return App::query()
            ->with('node')
            ->get()
            ->filter(fn (App $app): bool => $app->name === $selector
                || $app->domain === $selector
                || $app->url() === "https://{$selector}")
            ->values()
            ->first();
    }

    private function callerCanManageApp(Node $caller, App $app): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        $node = $app->node;

        if (! $node instanceof Node) {
            return false;
        }

        return DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $node->id)
            ->exists();
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
                'meta' => $meta,
            ],
        ], $status);
    }

    public function effect(): ActivityLogType
    {
        return ActivityLogType::Write;
    }

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return 'api:POST /apps/{app}/root';
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
            'root' => $this->optionalString(request(), 'root'),
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
