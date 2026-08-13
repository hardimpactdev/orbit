<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainerManager;
use App\Services\Ca\OrbitCaService;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Workspaces\WorkspaceRuntimeContainerManager;

final readonly class DoctorFrankenPhpRuntimeContainerManagers
{
    public function __construct(
        private DockerCommandBuilder $dockerCommands,
        private OrbitCaService $orbitCa,
        private AppDevelopmentInnerTlsPolicy $innerTlsPolicy,
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function forApp(): AppRuntimeContainerManager
    {
        return new AppRuntimeContainerManager(
            $this->dockerCommands,
            $this->orbitCa,
            $this->innerTlsPolicy,
            localExecutor: $this->localExecutor,
        );
    }

    public function forWorkspace(): WorkspaceRuntimeContainerManager
    {
        return new WorkspaceRuntimeContainerManager(
            $this->dockerCommands,
            $this->orbitCa,
            $this->innerTlsPolicy,
            localExecutor: $this->localExecutor,
        );
    }
}
