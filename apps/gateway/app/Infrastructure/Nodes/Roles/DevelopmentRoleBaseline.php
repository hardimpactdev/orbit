<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\AppDevCaddyManager;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\NodeStorageRootPreparer;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Nodes\EnrollmentProgram;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class DevelopmentRoleBaseline implements RoleBaseline
{
    public function __construct(
        private RolePrerequisites $prerequisites,
        private AgentCommandRunner $agents,
        private AppDevCaddyManager $caddy,
        private NodeRoleFirewallManager $firewall,
        private PrivateDnsManager $dns,
        private ManagedUserAccountResolver $accounts,
        private NodeSettingsNormalizer $nodeSettings,
        private NodeStorageRootPreparer $storageRootsPreparer,
        private ConfiguredStoragePathValidator $storagePaths,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $account = $this->accounts->resolve($node);
        $settings = $this->nodeSettings->fromStored($node->settings);
        $this->storageRootsPreparer->prepare(
            $node,
            $account,
            $this->storagePaths->validateEffective($settings, $node, $account),
        );
        $this->dns->converge($node);
        // Task VM enrollment trusts the software in its image.
        $this->prerequisites->converge(
            $node,
            RoleName::AppDev,
            $account,
            'app-dev.prerequisite_failed',
            CaddyRoleFailure::sshLabel(RoleName::AppDev),
            caddy: ! EnrollmentProgram::skipForTaskVm($node, EnrollmentProgram::CaddyPackage),
            packages: ! EnrollmentProgram::skipForTaskVm($node, EnrollmentProgram::prerequisites(RoleName::AppDev)),
        );
        // Hibernation wake sites log to these directories, and validation opens those logs as `caddy`.
        AgentStep::run(
            $this->agents,
            $node,
            'hibernation.prepare-directories',
            [
                'marker_directory' => RuntimeHibernation::MarkerDirectory,
                'log_directory' => RuntimeHibernation::AccessLogDirectory,
            ],
            'hibernation-directories',
            'app-dev.prerequisite_failed',
            CaddyRoleFailure::sshLabel(RoleName::AppDev),
        );
        $this->caddy->converge($node, RoleName::AppDev);
        $this->firewall->converge($node, RoleName::AppDev, $node->user);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->caddy->remove($node, RoleName::AppDev);
        $this->firewall->remove($node, RoleName::AppDev, $node->user);
        $this->dns->converge();
    }

    /**
     * Only the private DNS record lives on the Gateway.
     *
     * The Caddy sites and the firewall rule both live on the node itself, so
     * both would have run over SSH; the caller reports those as retained.
     */
    public function removeUnreachable(Node $node, NodeRole $assignment): void
    {
        $this->dns->converge();
    }
}
