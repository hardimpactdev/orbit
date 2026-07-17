<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles;

use App\Models\Node;
use App\Services\WebSockets\WebSocketRedisResolver;
use Illuminate\Http\JsonResponse;

final readonly class WebSocketRoleSettingsResolver
{
    public function __construct(
        private WebSocketRedisResolver $webSocketRedisResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|JsonResponse
     */
    public function resolve(array $settings): array|JsonResponse
    {
        $redisNodeName = is_string($settings['redis_node'] ?? null) ? $settings['redis_node'] : null;

        unset($settings['redis_node']);

        if (array_key_exists('redis_node_id', $settings)) {
            return $settings;
        }

        $redisNode = $redisNodeName === null
            ? null
            : Node::query()->where('name', $redisNodeName)->first();

        if (
            ! $redisNode instanceof Node
            || ! $this->webSocketRedisResolver->usableRedisNode($redisNode->id) instanceof Node
        ) {
            return response()->json([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'The websocket role requires an active database node with Redis.',
                    'meta' => [
                        'field' => 'redis_node',
                        'required_role' => 'database',
                        'required_service' => 'redis',
                    ],
                ],
            ], 422);
        }

        $settings['redis_node_id'] = $redisNode->id;

        return $settings;
    }
}
