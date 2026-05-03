<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

final readonly class NodeStoreController
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
        $this->addStringOption($arguments, '--ssh-user', $request, 'ssh_user');

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
}
