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

    public static function versionUnsupported(string $tool, string $version): self
    {
        return new self(
            code: 'tool.version_unsupported',
            message: "Tool '{$tool}' does not support version '{$version}'.",
            meta: [
                'field' => 'version',
                'reason' => 'unsupported_value',
                'tool' => $tool,
                'version' => $version,
            ],
        );
    }

    public static function runtimeUnsupported(string $tool, string $runtime): self
    {
        return new self(
            code: 'tool.runtime_unsupported',
            message: "Tool '{$tool}' does not support runtime '{$runtime}'.",
            meta: [
                'tool' => $tool,
                'runtime' => $runtime,
            ],
        );
    }

    public static function runtimePlatformUnsupported(
        string $tool,
        string $runtime,
        string $platform,
        ?string $platformFamily = null,
        ?string $implementationKey = null,
    ): self {
        $meta = [
            'tool' => $tool,
            'runtime' => $runtime,
            'platform' => $platform,
        ];

        if ($platformFamily !== null) {
            $meta['platform_family'] = $platformFamily;
        }

        if ($implementationKey !== null) {
            $meta['implementation_key'] = $implementationKey;
        }

        return new self(
            code: 'tool.runtime_platform_unsupported',
            message: "Tool '{$tool}' runtime '{$runtime}' is not supported on platform '{$platform}'.",
            meta: $meta,
        );
    }

    public static function instanceExists(
        string $node,
        string $tool,
        string $instance,
        string $source,
        ?string $process = null,
    ): self {
        $meta = [
            'node' => $node,
            'tool' => $tool,
            'instance' => $instance,
            'source' => $source,
        ];

        if ($process !== null) {
            $meta['process'] = $process;
        }

        return new self(
            code: 'tool.instance_exists',
            message: "Tool '{$tool}' instance '{$instance}' already exists on node '{$node}'.",
            meta: $meta,
        );
    }

    public static function endpointConflict(
        string $node,
        string $tool,
        string $instance,
        string $host,
        int $port,
        ?string $existingTool = null,
        ?string $existingInstance = null,
    ): self {
        $meta = [
            'node' => $node,
            'tool' => $tool,
            'instance' => $instance,
            'host' => $host,
            'port' => $port,
        ];

        if ($existingTool !== null) {
            $meta['existing_tool'] = $existingTool;
        }

        if ($existingInstance !== null) {
            $meta['existing_instance'] = $existingInstance;
        }

        return new self(
            code: 'tool.endpoint_conflict',
            message: "Tool '{$tool}' instance '{$instance}' endpoint {$host}:{$port} conflicts with existing intent.",
            meta: $meta,
        );
    }

    public static function nodeRoleRequired(string $tool, string $node, string $requiredRole): self
    {
        return new self(
            code: 'node.role_required',
            message: "Tool '{$tool}' requires node '{$node}' to have active role '{$requiredRole}'.",
            meta: [
                'node' => $node,
                'required_role' => $requiredRole,
                'tool' => $tool,
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

    public static function processMissing(string $tool, string $node, string $action): self
    {
        return new self(
            code: 'tool.process_missing',
            message: "Tool '{$tool}' has no related lifecycle process on node '{$node}'.",
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
    public static function processAmbiguous(string $tool, string $node, string $action, array $processes): self
    {
        return new self(
            code: 'tool.process_ambiguous',
            message: "Tool '{$tool}' has multiple related lifecycle processes on node '{$node}'.",
            meta: [
                'tool' => $tool,
                'node' => $node,
                'action' => $action,
                'processes' => $processes,
            ],
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
