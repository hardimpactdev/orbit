<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use JsonException;

final readonly class WebSocketRuntimeContainerApplyWarnings
{
    public const string APPLY_OPERATION = 'websocket-runtime-container-apply';

    public function __construct(
        private OperationRunRecorder $operationRuns,
    ) {}

    public function record(Node $node, RemoteShellResult $result): void
    {
        $messages = $this->messages($result);

        if ($messages === []) {
            return;
        }

        $run = OperationRun::query()
            ->where('operation_id', self::APPLY_OPERATION)
            ->where('target_node_id', $node->id)
            ->latest('created_at')
            ->first();

        if (! $run instanceof OperationRun) {
            return;
        }

        foreach ($messages as $message) {
            $this->operationRuns->appendStep(
                $run->id,
                'current-image-verification',
                'warning',
                $message,
            );
        }
    }

    /**
     * @return list<string>
     */
    private function messages(RemoteShellResult $result): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode(trim($result->stdout), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        $messages = $this->messagesFromMeta($payload);

        if ($messages !== []) {
            return $messages;
        }

        $warning = $this->stringValue(data_get(target: $payload, key: 'success.data.warning'));

        return $warning === null ? [] : [$warning];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>
     */
    private function messagesFromMeta(array $payload): array
    {
        $warnings = data_get(target: $payload, key: 'success.meta.warnings');

        if (! is_array($warnings)) {
            return [];
        }

        $messages = [];

        foreach ($warnings as $warning) {
            $message = $this->stringValue(is_array($warning) ? $warning['message'] ?? null : $warning);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return array_values(array_unique($messages));
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
