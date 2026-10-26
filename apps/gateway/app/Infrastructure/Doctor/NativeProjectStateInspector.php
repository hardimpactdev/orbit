<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ProjectInspectionData;
use App\Domain\Doctor\ProjectStateInspector;
use App\Domain\Instances\InstanceProvisionProgress;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\Tasks\TaskWorkspaceLifecycle;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

final readonly class NativeProjectStateInspector implements ProjectStateInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private CommandDeadline $deadline,
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
    ) {}

    public function inspect(Project $project, Node $node): ProjectInspectionData
    {
        $host = $node->wireguard_ip;
        if (! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }

        try {
            $account = null;
            $repository = GitRepositoryOrigin::validate($project->repository_url);
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        $checkouts = [];
        $instances = $project
            ->instances()
            ->where('node_id', $node->id)
            ->where('status', '!=', InstanceState::Removing)
            ->with(['project', 'tasks'])
            ->orderBy('id')
            ->get()
            ->filter(static fn (Instance $instance): bool => ! InstanceProvisionProgress::isInFlight(
                $instance,
                TaskWorkspaceLifecycle::settledState($instance),
            ));
        foreach ($instances as $instance) {
            if ($instance->placedOnAppProd()) {
                $home = $instance->production_home;
                $user = $instance->production_user;
                if (! is_string($home) || $home === '' || ! is_string($user) || $user === '') {
                    throw new DoctorInspectionException;
                }

                $checkouts[] = [
                    'instance_id' => (int) $instance->id,
                    'path' => $instance->checkout_path,
                    'root' => $home,
                    'user' => $user,
                    'production' => true,
                ];

                continue;
            }

            $account ??= $this->accounts->resolve($node);
            $root = $this->developmentRoot($node, $account);
            $checkouts[] = [
                'instance_id' => (int) $instance->id,
                'path' => $instance->checkout_path,
                'root' => $root,
                'user' => $account->user,
                'production' => false,
            ];
        }
        if ($checkouts === []) {
            return new ProjectInspectionData(0, true);
        }

        $ids = array_column($checkouts, 'instance_id');
        try {
            $result = $this->agents->run($node, 'doctor.inspect-project', [
                'repository' => $repository,
                'checkouts' => $checkouts,
            ], $this->deadline->cap(30.0));
            $observations = $result['checkouts'] ?? null;
            if (! is_array($observations) || ! array_is_list($observations) || count($observations) !== count($checkouts)) {
                throw new DoctorInspectionException;
            }
            $mismatchingInstanceIds = [];
            $failedInstanceIds = [];
            foreach ($observations as $index => $observation) {
                if (! is_array($observation)
                    || ($observation['instance_id'] ?? null) !== $ids[$index]
                    || ! array_key_exists('matches', $observation)
                    || ($observation['matches'] !== null && ! is_bool($observation['matches']))) {
                    throw new DoctorInspectionException;
                }
                if ($observation['matches'] === null) {
                    $failedInstanceIds[] = $ids[$index];
                } elseif (! $observation['matches']) {
                    $mismatchingInstanceIds[] = $ids[$index];
                }
            }
        } catch (\Throwable) {
            return new ProjectInspectionData(count($checkouts), true, [], $ids);
        }

        return new ProjectInspectionData(count($checkouts), $mismatchingInstanceIds === [], $mismatchingInstanceIds, $failedInstanceIds);
    }

    /** Resolves the single managed apps root for development checkouts. */
    private function developmentRoot(Node $node, ManagedUserAccount $account): string
    {
        try {
            $appsRoot = $this->storageRoots
                ->resolveApps($this->nodeSettings->fromStored($node->settings), $account);
        } catch (\Throwable) {
            throw new DoctorInspectionException;
        }

        return $appsRoot->value;
    }
}
