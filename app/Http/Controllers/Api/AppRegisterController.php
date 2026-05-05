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

final class AppRegisterController implements Loggable
{
    private ?App $activitySubject = null;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->error('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        if ($caller->role === 'app') {
            return $this->error('caller_role_not_allowed', 'This command may only be run from a control or gateway node.', ['caller_role' => 'app'], 403);
        }

        $targetNode = $this->targetNode($request);

        if ($targetNode instanceof Node && ! $this->callerCanRegisterOnNode($caller, $targetNode)) {
            return $this->error('authorization_failed', "This node is not authorized to register apps on '{$targetNode->name}'.", ['node' => $targetNode->name], 403);
        }

        $arguments = [
            'name' => $this->optionalString($request, 'name'),
            '--json' => true,
        ];

        $this->addStringOption($arguments, '--node', $request, 'node');
        $this->addStringOption($arguments, '--path', $request, 'path');
        $this->addStringOption($arguments, '--root', $request, 'root');
        $this->addStringOption($arguments, '--php-version', $request, 'php_version');
        $this->addStringOption($arguments, '--domain', $request, 'domain');

        $exitCode = Artisan::call('app:register', $arguments);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $name = $this->optionalString($request, 'name');
        $this->activitySubject = $name === null
            ? null
            : App::query()->where('name', $name)->first();

        return response()->json($payload, $exitCode === 0 ? 200 : 422);
    }

    private function targetNode(Request $request): ?Node
    {
        $nodeName = $this->optionalString($request, 'node');

        if ($nodeName !== null) {
            return Node::query()->where('name', $nodeName)->first();
        }

        $name = $this->optionalString($request, 'name');

        if ($name === null) {
            return null;
        }

        return App::query()
            ->with('node')
            ->where('name', $name)
            ->first()
            ?->node;
    }

    private function callerCanRegisterOnNode(Node $caller, Node $node): bool
    {
        if ($caller->role === 'gateway') {
            return true;
        }

        return DB::table('node_access')
            ->where('consumer_node_id', $caller->id)
            ->where('serving_node_id', $node->id)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addStringOption(array &$arguments, string $option, Request $request, string $key): void
    {
        $value = $this->optionalString($request, $key);

        if ($value === null) {
            return;
        }

        $arguments[$option] = $value;
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
        return 'api:POST /apps/register';
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
            'name' => $this->optionalString(request(), 'name'),
            'node' => $this->optionalString(request(), 'node'),
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
