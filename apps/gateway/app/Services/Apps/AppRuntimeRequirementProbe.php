<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Doctor\DriftEntry;
use App\Enums\Apps\AppRuntimeKind;
use App\Enums\Apps\InstanceDriver;
use App\Enums\DriftKind;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppRuntimeRequirementProbe
{
    public function __construct(
        private RemoteAppRuntimeExtensionsProbe $extensionsProbe,
        private AppRuntimeContainerRenderer $renderer,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @return list<DriftEntry>
     */
    public function drift(Instance $instance): array
    {
        $required = $instance->runtimeRequirements()->normalizedPhpExtensions();

        if ($required === []) {
            return [];
        }

        $instance->loadMissing('app');
        $app = $instance->app;

        if ($instance->driver !== InstanceDriver::Orbit || $app->runtimeKind() !== AppRuntimeKind::Php) {
            return [];
        }

        $node = $this->placement->runtimeNode($app, $instance);

        if (! $node instanceof Node) {
            return [
                new DriftEntry(
                    family: 'app',
                    key: 'app.runtime_extensions_unverifiable',
                    kind: DriftKind::Unverifiable,
                    summary: "Required PHP extensions for app '{$app->name}' instance '{$instance->name}' cannot be verified because the app has no owning node.",
                    detail: [
                        'app' => $app->name,
                        'instance' => $instance->name,
                        'required_extensions' => $required,
                    ],
                ),
            ];
        }

        $container = $this->renderer->containerName($app);
        $result = $this->extensionsProbe->probe($node, $container);

        if ($result['exit_code'] !== 0) {
            return [
                new DriftEntry(
                    family: 'app',
                    key: 'app.runtime_extensions_unverifiable',
                    kind: DriftKind::Unverifiable,
                    summary: "Required PHP extensions for app '{$app->name}' instance '{$instance->name}' cannot be verified.",
                    detail: [
                        'app' => $app->name,
                        'instance' => $instance->name,
                        'container' => $container,
                        'required_extensions' => $required,
                        'error' => trim($result['stderr']) ?: trim($result['stdout']),
                    ],
                ),
            ];
        }

        $observed = array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            array_filter(explode("\n", $result['stdout'])),
        );

        $missing = array_values(array_diff($required, $observed));

        if ($missing === []) {
            return [];
        }

        return [
            new DriftEntry(
                family: 'app',
                key: 'app.runtime_extension_missing',
                kind: DriftKind::Divergent,
                summary: "App '{$app->name}' instance '{$instance->name}' is missing required PHP extension(s): "
                .implode(', ', $missing)
                .'.',
                detail: [
                    'app' => $app->name,
                    'instance' => $instance->name,
                    'container' => $container,
                    'required_extensions' => $required,
                    'observed_extensions' => array_values(array_unique($observed)),
                    'missing_extensions' => $missing,
                ],
            ),
        ];
    }
}
