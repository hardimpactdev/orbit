<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Models\Node;
use App\Services\Processes\ProcessListPayload;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Sdk\Laravel\GatewayApiException;

final readonly class ProcessListController implements Loggable
{
    public function __construct(
        private ProcessListPayload $payload,
    ) {}

    #[OpenApiResponse(
        status: 200,
        description: 'Process definitions with concrete status for a node, instance, workspace, or app hostname context.',
        type: 'array{success: array{data: array{context: array{node: string, app: string|null, instance: string|null, workspace: string|null}, processes: list<array{node: string, app: string|null, instance: string|null, workspace: string|null, key: string, label: string, name: string, command: string|null, restart_policy: string, crash_notification: string, runtime: string, tool: string|null, service: array<string, mixed>|null, runtime_unit: string, status: \'starting\'|\'running\'|\'stopping\'|\'stopped\'|\'restarting\'|\'crashed\'|\'unknown\', last_event: array{id: int, type: string}|null}>}, meta: object}}',
    )]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        if (! $caller instanceof Node) {
            return $this->fail('authorization_failed', 'Peer identity unknown.', [], 403);
        }

        try {
            $data = $this->payload->forContext(
                nodeName: $this->stringQuery($request, 'node'),
                appName: $this->stringQuery($request, 'instance'),
                workspaceName: $this->stringQuery($request, 'workspace'),
                caller: $caller,
                appHostname: $this->stringQuery($request, 'app'),
            );
        } catch (GatewayApiException $e) {
            return $this->fail(
                code: $e->errorCode() ?? 'validation_failed',
                message: $e->getMessage(),
                meta: $e->errorMeta(),
                status: $e->errorCode() === 'authorization_failed' ? 403 : 400,
            );
        }

        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => (object) [],
            ],
        ]);
    }

    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fail(string $code, string $message, array $meta, int $status): JsonResponse
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
        return ActivityLogType::Read;
    }

    public function type(): string
    {
        return 'api:GET /processes';
    }

    public function subject(): ?Model
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}
