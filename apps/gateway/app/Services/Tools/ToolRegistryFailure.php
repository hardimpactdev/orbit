<?php

declare(strict_types=1);

namespace App\Services\Tools;

/** @mago-expect lint:too-many-methods */
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

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function validation(string $field, string $value, string $message, array $meta = []): self
    {
        return new self(
            code: 'validation_failed',
            message: $message,
            meta: [
                'field' => $field,
                'value' => $value,
                ...$meta,
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

    public static function runtimeMissing(string $tool, string $node, string $action): self
    {
        return new self(
            code: 'tool.runtime_missing',
            message: "Tool '{$tool}' declares {$action}, but no direct runtime is configured on node '{$node}'.",
            meta: [
                'tool' => $tool,
                'node' => $node,
                'action' => $action,
            ],
        );
    }

    /**
     * @param  list<string>  $processes
     */
    public static function runtimeAmbiguous(
        string $tool,
        string $node,
        string $action,
        array $processes,
        bool $toolOwnedRuntime,
    ): self {
        return new self(
            code: 'tool.runtime_ambiguous',
            message: "Tool '{$tool}' maps to more than one runtime on node '{$node}'.",
            meta: [
                'tool' => $tool,
                'node' => $node,
                'action' => $action,
                'processes' => $processes,
                'tool_owned_runtime' => $toolOwnedRuntime,
            ],
        );
    }

    /**
     * @param  list<string>  $supportedOperatingSystems
     */
    public static function unsupportedOnNode(
        string $tool,
        string $node,
        ?string $platform,
        array $supportedOperatingSystems,
    ): self {
        return new self(
            code: 'tool.unsupported_on_node',
            message: "Tool '{$tool}' does not support node '{$node}' platform.",
            meta: [
                'tool' => $tool,
                'node' => $node,
                'platform' => $platform,
                'supported_operating_systems' => $supportedOperatingSystems,
            ],
        );
    }

    /** @param array<string, mixed> $context */
    public static function constraintUnsatisfied(
        string $tool,
        string $node,
        string $constraint,
        array $context,
    ): self {
        return new self(
            code: 'tool.constraint_unsatisfied',
            message: "Tool '{$tool}' install preflight failed on node '{$node}': {$constraint} constraint is not satisfied.",
            meta: [
                'tool' => $tool,
                'node' => $node,
                'action' => 'install',
                'constraint' => $constraint,
                ...$context,
            ],
        );
    }

    public static function remoteActionFailed(
        string $tool,
        string $node,
        string $action,
        int $exitCode,
        string $stderr,
    ): self {
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

    /**
     * @param  array<string, mixed>  $meta
     */
    public static function agentUnreachable(string $message, array $meta = []): self
    {
        return new self(
            code: 'node.agent_unreachable',
            message: $message,
            meta: $meta,
        );
    }

    public static function authorization(string $message): self
    {
        return new self(
            code: 'authorization_failed',
            message: $message,
            meta: [],
        );
    }

    public static function nodeTargetRequired(): self
    {
        return new self(
            code: 'node_target_required',
            message: 'A node target is required. Provide --node.',
            meta: ['field' => 'node'],
        );
    }
}
