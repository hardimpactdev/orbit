<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

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

        if (! in_array($caller->role, ['control', 'gateway'], true)) {
            return $this->forbidden();
        }

        $arguments = [
            'name' => $this->optionalString($request, 'name'),
            '--json' => true,
        ];

        $this->addStringOption($arguments, '--role', $request, 'role');
        $this->addStringOption($arguments, '--host', $request, 'host');
        $this->addStringOption($arguments, '--environment', $request, 'environment');
        $this->addStringOption($arguments, '--tld', $request, 'tld');
        $this->addStringOption($arguments, '--user', $request, 'user');

        $exitCode = Artisan::call('node:new', $arguments);

        /** @var array<string, mixed> $payload */
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        return response()->json($payload, $exitCode === 0 ? 200 : 422);
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
