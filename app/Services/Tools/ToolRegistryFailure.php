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
}
