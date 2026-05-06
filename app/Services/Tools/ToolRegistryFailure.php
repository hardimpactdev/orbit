<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolRegistryFailure
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public string $code,
        public string $message,
        public array $meta,
    ) {}

    public static function validation(string $field, string $value, string $message): self
    {
        return new self(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => $field,
                'value' => $value,
            ],
        );
    }

    public static function notFound(string $tool, string $node): self
    {
        return new self(
            code: 'tool.not_found',
            message: "Tool '{$tool}' not found on node '{$node}'.",
            meta: [
                'tool' => $tool,
                'node' => $node,
            ],
        );
    }

    public static function unsupportedAction(string $tool, string $action): self
    {
        return new self(
            code: 'tool.unsupported_action',
            message: "Tool '{$tool}' does not support {$action}.",
            meta: [
                'tool' => $tool,
                'action' => $action,
            ],
        );
    }

    public static function remoteActionFailed(string $tool, string $node, string $action, int $exitCode, string $stderr): self
    {
        return new self(
            code: 'tool.remote_action_failed',
            message: "Tool '{$tool}' {$action} failed on node '{$node}'.",
            meta: [
                'tool' => $tool,
                'node' => $node,
                'action' => $action,
                'exit_code' => $exitCode,
                'stderr' => $stderr,
            ],
        );
    }
}
