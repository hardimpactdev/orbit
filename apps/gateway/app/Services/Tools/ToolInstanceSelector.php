<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\NodeTool;

final readonly class ToolInstanceSelector
{
    public function __construct(
        public string $tool,
        public string $instanceKey,
        public ?string $versionFamily,
        public ?string $expectedVersion,
    ) {}

    public static function forInstall(
        ToolCatalog $catalog,
        string $tool,
        ToolVersionRequest $version,
        ?string $instance,
    ): self|ToolRegistryFailure {
        $tool = trim($tool);
        $instance = is_string($instance) && trim($instance) !== '' ? trim($instance) : null;

        $families = $catalog->supportedVersionFamilies($tool);

        if ($families === []) {
            if (! $version->isEmpty()) {
                return ToolRegistryFailure::versionUnsupported($tool, (string) $version->value);
            }

            return new self(
                tool: $tool,
                instanceKey: $instance ?? NodeTool::defaultInstanceKey($tool),
                versionFamily: null,
                expectedVersion: null,
            );
        }

        if ($version->isEmpty()) {
            if (count($families) !== 1) {
                return ToolRegistryFailure::validation(
                    field: 'version',
                    value: '',
                    message: "Tool '{$tool}' requires a version selection.",
                    meta: [
                        'reason' => 'required',
                        'tool' => $tool,
                    ],
                );
            }

            $family = (string) array_key_first($families);
            $resolved = [
                'version_family' => $family,
                'expected_version' => $families[$family]['default'],
            ];
        } else {
            $resolved = $catalog->resolveVersionRequest($tool, (string) $version->value);

            if ($resolved === null) {
                return ToolRegistryFailure::versionUnsupported($tool, (string) $version->value);
            }
        }

        return new self(
            tool: $tool,
            instanceKey: $instance ?? "{$tool}:{$resolved['version_family']}",
            versionFamily: $resolved['version_family'],
            expectedVersion: $resolved['expected_version'],
        );
    }
}
