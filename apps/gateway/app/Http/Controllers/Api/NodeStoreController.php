<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

#[RequiresPermission('node:new', servingNode: ServingNode::Gateway)]
final readonly class NodeStoreController implements Loggable
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return $this->forbidden();
        }

        $arguments = [
            'name' => $this->optionalString($request, 'name'),
            '--json' => true,
        ];

        $this->addRoleOption($arguments, $request);
        $this->addStringOption($arguments, '--host', $request, 'host');
        $this->addStringOption($arguments, '--ingress', $request, 'ingress_node');
        $this->addStringOption($arguments, '--environment', $request, 'environment');
        $this->addStringOption($arguments, '--tld', $request, 'tld');
        $this->addStringOption($arguments, '--user', $request, 'user');
        $this->addStringOption($arguments, '--host-key-fingerprint', $request, 'host_key_fingerprint');
        $this->addStringOption($arguments, '--self-grant', $request, 'self_grant');
        $this->addStringOption($arguments, '--self-grant-permissions', $request, 'self_grant_permissions');
        $this->addArrayOption($arguments, '--grant-to', $request, 'grant_to');
        $this->addStringOption($arguments, '--grant-to-preset', $request, 'grant_to_preset');
        $this->addStringOption($arguments, '--grant-to-permissions', $request, 'grant_to_permissions');
        $this->addArrayOption($arguments, '--grant-from', $request, 'grant_from');
        $this->addStringOption($arguments, '--grant-from-preset', $request, 'grant_from_preset');
        $this->addStringOption($arguments, '--grant-from-permissions', $request, 'grant_from_permissions');
        $this->addArrayOption($arguments, '--agent-tool', $request, 'agent_tools');

        $exitCode = $this->callNodeNewInGatewayContext($arguments);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        return response()->json($payload, $exitCode === 0 ? 200 : 422);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function callNodeNewInGatewayContext(array $arguments): int
    {
        $previousGatewayContext = config('orbit.is_gateway');

        try {
            config(['orbit.is_gateway' => true]);

            return Artisan::call('node:new', $arguments);
        } finally {
            config(['orbit.is_gateway' => $previousGatewayContext]);
        }
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'This caller cannot create nodes.',
                'meta' => [],
            ],
        ], 403);
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

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addArrayOption(array &$arguments, string $option, Request $request, string $key): void
    {
        $value = $request->input($key);

        if (! is_array($value) || $value === []) {
            return;
        }

        $filtered = array_values(array_filter($value, fn ($item): bool => is_string($item) && $item !== ''));

        if ($filtered !== []) {
            $arguments[$option] = $filtered;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function addRoleOption(array &$arguments, Request $request): void
    {
        $roles = $request->input('roles');

        if (is_array($roles)) {
            $resolvedRoles = array_values(array_filter($roles, fn ($role): bool => is_string($role) && $role !== ''));

            if ($resolvedRoles !== []) {
                $arguments['--role'] = $resolvedRoles;

                return;
            }
        }

        $role = $this->optionalString($request, 'role');

        if ($role !== null) {
            $arguments['--role'] = [$role];
        }
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
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
        return 'node.created';
    }

    public function activityLogAction(): string
    {
        return $this->type();
    }

    public function subject(): ?Model
    {
        $name = request('name');

        if (! is_string($name) || $name === '') {
            return null;
        }

        return Node::query()->where('name', $name)->first();
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
            'name' => $this->requestString('name'),
            'role' => $this->requestString('role'),
            'roles' => request('roles'),
            'environment' => $this->requestString('environment'),
            'tld' => $this->requestString('tld'),
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
        $name = $this->requestString('name');

        return $name !== null ? "Created node {$name}." : null;
    }

    public function activityLogDescription(): ?string
    {
        return $this->description();
    }

    private function requestString(string $key): ?string
    {
        $value = request($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
