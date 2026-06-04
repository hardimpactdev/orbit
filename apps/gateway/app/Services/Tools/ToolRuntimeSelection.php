<?php

declare(strict_types=1);

namespace App\Services\Tools;

final readonly class ToolRuntimeSelection
{
    public function __construct(
        public string $tool,
        public string $runtime,
        public string $platform,
    ) {}

    public static function resolve(
        ToolCatalog $catalog,
        string $tool,
        ?string $runtime,
        string $platform,
    ): self|ToolRegistryFailure {
        $tool = trim($tool);
        $platform = trim($platform);
        $runtime = is_string($runtime) && trim($runtime) !== ''
            ? trim($runtime)
            : $catalog->defaultRuntime($tool);

        if ($runtime === null || $runtime === '') {
            return ToolRegistryFailure::validation(
                field: 'runtime',
                value: '',
                message: "Tool '{$tool}' requires a runtime selection.",
                meta: [
                    'reason' => 'required',
                    'tool' => $tool,
                ],
            );
        }

        $supportedRuntimes = $catalog->supportedRuntimes($tool);

        if (! array_key_exists($runtime, $supportedRuntimes)) {
            return ToolRegistryFailure::runtimeUnsupported($tool, $runtime);
        }

        $platforms = $supportedRuntimes[$runtime]['platforms'];

        if (! in_array($platform, $platforms, true)) {
            return ToolRegistryFailure::runtimePlatformUnsupported($tool, $runtime, $platform);
        }

        return new self(
            tool: $tool,
            runtime: $runtime,
            platform: $platform,
        );
    }
}
