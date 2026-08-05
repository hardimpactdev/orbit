<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use App\Actions\ApplicationLogs\StartApplicationLogStream;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Core\Http\JsonEnvelope;
use Throwable;

/**
 * Shared HTTP response helpers for application log endpoints.
 *
 * @phpstan-type Result array{response: JsonResponse, subject: ?Model, properties: array<string, mixed>}
 */
final readonly class ApplicationLogHttpResponses
{
    /**
     * @param  array<string, mixed>  $properties
     * @return Result
     */
    public static function result(
        JsonResponse $response,
        ?Model $subject = null,
        array $properties = [],
    ): array {
        return [
            'response' => $response,
            'subject' => $subject,
            'properties' => $properties,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function error(string $code, string $message, array $meta, int $status): JsonResponse
    {
        return response()->json(JsonEnvelope::failure($code, $message, $meta), $status);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return Result
     */
    public static function failure(string $code, string $message, array $meta, int $status): array
    {
        return self::result(self::error($code, $message, $meta, $status));
    }

    public static function optionalString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public static function gatewayUrl(Request $request): string
    {
        $settingsUrl = LocalGatewaySettings::current()->gateway_url;

        if (is_string($settingsUrl) && trim($settingsUrl) !== '') {
            return rtrim(trim($settingsUrl), characters: '/');
        }

        return rtrim($request->getSchemeAndHttpHost(), characters: '/');
    }

    public static function acceptedStream(string $operationRunId): JsonResponse
    {
        $response = response()->json(JsonEnvelope::success([
            'operation' => [
                'uuid' => $operationRunId,
                'stream_descriptor_url' => "/api/operations/{$operationRunId}/stream",
                'events_url' => "/api/operations/{$operationRunId}/events",
            ],
        ]), status: 202);
        $content = (string) $response->getContent();
        $response->headers->set('Content-Length', (string) strlen($content));

        return $response;
    }

    /**
     * @param  array{node: Node, absolute_path: string, authorized_root: string, lines: int, operation_stream?: array<string, mixed>}  $target
     */
    public static function scheduleFollow(StartApplicationLogStream $startApplicationLogStream, array $target): void
    {
        app()->terminating(static function () use ($startApplicationLogStream, $target): void {
            try {
                $startApplicationLogStream->follow($target, static function (string $_output): void {});
            } catch (Throwable $throwable) {
                report($throwable);
            }
        });
    }
}
