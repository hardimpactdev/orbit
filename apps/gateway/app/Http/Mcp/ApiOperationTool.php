<?php

declare(strict_types=1);

namespace App\Http\Mcp;

use App\Domain\Operations\OperationPoll;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/** One Gateway API operation offered as an MCP tool. */
final class ApiOperationTool extends Tool
{
    public function __construct(
        private readonly ToolDefinition $definition,
        private readonly ApiDispatcher $dispatcher,
    ) {
        $this->name = $definition->name;
        $this->title = $definition->title;
        $this->description = $definition->description;
    }

    public function handle(Request $request, HttpRequest $caller): Response
    {
        $arguments = $request->all();
        $result = $this->dispatcher->dispatch($this->definition, $arguments, $caller);
        $json = $result->json();

        if ($result->failed()) {
            return Response::error($json === null
                ? "HTTP {$result->status}"
                : (string) json_encode(['status' => $result->status, ...$json], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if ($json === null) {
            return Response::text(trim($result->body) === '' ? "Done (HTTP {$result->status})." : $result->body);
        }

        if ($this->definition->startsOperation && $result->status === 202 && is_array($json['data'] ?? null)) {
            return Response::json(self::startedOperation($json));
        }

        return Response::json($json);
    }

    /**
     * A started operation and the hint that tells the agent how to follow it, with no held request and no stream
     * (docs/reference/mcp.mdx#operations).
     *
     * @param  array<string, mixed>  $json
     * @return array{operation: mixed, poll: array{tool: string, after_seconds: int}, meta?: mixed}
     */
    private static function startedOperation(array $json): array
    {
        $operation = $json['data'];
        $after = is_array($operation) && is_int($operation['poll_after_seconds'] ?? null) ? $operation['poll_after_seconds'] : OperationPoll::MinimumSeconds;
        $started = ['operation' => $operation, 'poll' => ['tool' => 'operation-show', 'after_seconds' => $after]];

        return array_key_exists('meta', $json) ? [...$started, 'meta' => $json['meta']] : $started;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        $schema = $this->definition->inputSchema;
        $schema['properties'] = (object) ($schema['properties'] ?? []);

        return [
            'name' => $this->definition->name,
            'title' => $this->definition->title,
            'description' => $this->definition->description,
            'inputSchema' => $schema,
            'annotations' => [
                'readOnlyHint' => $this->definition->readsOnly(),
                'destructiveHint' => $this->definition->method === 'DELETE',
                'idempotentHint' => in_array($this->definition->method, ['GET', 'PUT', 'DELETE'], true),
                'openWorldHint' => false,
            ],
        ];
    }
}
