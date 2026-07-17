<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Models\Node;
use App\Services\WebSockets\WebSocketValkeyResolver;
use Illuminate\Http\JsonResponse;

final readonly class WebSocketRoleSettingsResolver
{
    public function __construct(
        private WebSocketValkeyResolver $webSocketValkeyResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|JsonResponse
     */
    public function resolve(array $settings): array|JsonResponse
    {
        $valkeyNodeName = is_string($settings['valkey_node'] ?? null) ? $settings['valkey_node'] : null;

        unset($settings['valkey_node']);

        if (array_key_exists('valkey_node_id', $settings)) {
            return $settings;
        }

        $valkeyNode = $valkeyNodeName === null
            ? null
            : Node::query()->where('name', $valkeyNodeName)->first();

        if (
            ! $valkeyNode instanceof Node
            || ! $this->webSocketValkeyResolver->usableValkeyNode($valkeyNode->id) instanceof Node
        ) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The websocket role requires an active database node with Valkey.',
                    'meta' => [
                        'field' => 'valkey_node',
                        'required_role' => 'database',
                        'required_service' => 'valkey',
                    ],
                ],
            ], 422);
        }

        $settings['valkey_node_id'] = $valkeyNode->id;

        return $settings;
    }
}
