<?php

declare(strict_types=1);

namespace App\Services\Php;

use App\Actions\Apps\EnactAppRuntime;
use App\Actions\Workspaces\SetupWorkspace;
use App\Contracts\PhpRuntimeArtifactConverger;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;

final readonly class AgentPushPhpRuntimeArtifactConverger implements PhpRuntimeArtifactConverger
{
    public function __construct(
        private EnactAppRuntime $enactAppRuntime,
        private SetupWorkspace $setupWorkspace,
    ) {}

    public function forApp(App $app): array
    {
        return $this->enactAppRuntime->handle($app);
    }

    public function forWorkspace(Workspace $workspace, Node $node): array
    {
        $warnings = [];
        $runtimeWarning = $this->setupWorkspace->enactRuntimeContainer($workspace, $node);

        if (is_array($runtimeWarning)) {
            $warnings[] = $runtimeWarning;
        }

        return [
            ...$warnings,
            ...$this->setupWorkspace->registerProxyRoutes($workspace),
        ];
    }
}
