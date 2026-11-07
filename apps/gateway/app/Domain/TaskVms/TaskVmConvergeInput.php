<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\RoleName;
use App\Domain\Proof\ProofSettings;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\Project;
use App\Support\ValidatedData;

/**
 * The input of the one converge of a task VM (swamp/ansible/task-vm-role.yml), which the image refresh and every task start run
 * (docs/cli/tasks/vm-image-refresh.mmd, docs/cli/tasks/provision.mmd). On a current image it changes nothing; it catches up the delta
 * between the image and `$commit`.
 */
final readonly class TaskVmConvergeInput
{
    /** The Composer and Bun projects of the warm checkout, as the Project's setup steps install them. */
    private const array Composer = ['.', 'apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];

    private const array Bun = ['apps/web', 'apps/pi-server', 'apps/desktop'];

    public function __construct(private TaskVmSettings $settings, private SwampSshExecutionInput $ssh, private ProofSettings $proof,
        private RecipeImageBuilds $recipes, private TaskVmSlotWorkspace $workspace, private AgentDelivery $delivery,
        private LeafCertificateSigner $certificates, private PiConnection $pi) {}

    /**
     * The converge of a VM on `$host` to `$commit`; a task start names the task branch to start there, and its `$guest` Node: the converge then
     * reaches the VM over WireGuard with its pinned host key as soon as sshd answers, and reads nothing back from the host.
     *
     * @return array{converge:array<string,mixed>,recipe_identity:string}
     */
    public function for(Node $host, string $commit, ?string $branch = null, ?Node $guest = null): array
    {
        $location = $this->settings->host($host->id);
        $architecture = MachineRecipe::architecture($host->architecture)
            ?? throw new ResourceOperationException('task_vm.converge_unavailable', 'The host architecture is unsupported.', 409);
        $recipe = $this->recipes->currentRecipe();
        $recipe->profile('base', $architecture);
        $pi = $recipe->tools['pi']->artifacts[$architecture] ?? throw new ResourceOperationException('task_vm.converge_unavailable', 'The recipe holds no Pi server for this architecture.', 409);
        $gateway = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($query) => $query
            ->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))->sole();
        $identity = $this->ssh->identity();
        $target = $guest === null ? ['host' => '', 'user' => 'orbit', 'port' => 22, 'host_keys' => []]
            : array_intersect_key($this->ssh->forNode($guest)['target'], array_flip(['host', 'user', 'port', 'host_keys']));

        return ['recipe_identity' => $recipe->identity, 'converge' => [
            'project' => $location->project, 'jump' => $this->ssh->forNode($host),
            'ssh' => ['identity_file' => $identity['identity_file'], 'target' => $target], ...$guest === null ? [] : ['direct' => true],
            'profile' => 'base', 'image' => true,
            'nested' => ['subnet' => TaskVmReadyCopiesIntent::Subnet, 'interface' => TaskVmReadyCopiesIntent::Network],
            'recipe' => json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR),
            'vp_lock' => VitePlusRuntimeLock::read($recipe->tools['vp']),
            'pi' => ['sha256' => $pi->digest, 'source' => ['ssh' => $this->ssh->forNode($gateway), 'path' => config()->string('orbit.home').'/sandbox-artifacts/'.$pi->location]],
            // The agent the image carries: the pinned build for this host's platform. A task start writes its unit and secret.
            'boot' => ['authorized_key' => $identity['public_key'], 'agent' => $this->agent($host),
                // The same for every task VM, so the base carries them and a start's identity unit only unpacks what is its own.
                'trust' => ['certificate' => $this->certificates->rootCertificate(),
                    'control_ip' => ValidatedData::string(Node::query()->findOrFail($identity['control_node_id'])->wireguard_ip),
                    'gateway_ip' => ValidatedData::string($gateway->wireguard_ip), 'pi_port' => $this->pi->port()]],
            'checkout' => $this->checkout($commit, $branch),
            // The outer VM joins the nested WireGuard network as its operator; the hub is the nested Gateway.
            'fleet' => [...TaskVmReadyCopiesIntent::fleet(), 'operator' => ['address' => '10.45.0.10', 'hub' => '10.45.0.1', 'network' => '10.45.0.0/24']],
        ]];
    }

    /** The commit that the Gateway runs: a refreshed image holds the playbooks and pins of the release it serves. */
    public function releaseCommit(): string
    {
        $inventory = GatewayReleaseLayout::fromConfig()->observe();
        $commit = $inventory->current === null ? null : ($inventory->commits[$inventory->current] ?? null);
        if (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new ResourceOperationException('task_vm.converge_unavailable', 'The Gateway release has no commit to converge to.', 409);
        }

        return $commit;
    }

    /**
     * The warm checkout of the slots' Project: the path is the one a task's workspace Instance names, `<apps>/<slug>/workspace` of the
     * task VM account.
     *
     * @return array<string,mixed>
     */
    private function checkout(string $commit, ?string $branch): array
    {
        $project = Project::query()->findOrFail($this->proof->projectId());

        return ['path' => $this->workspace->checkoutPath($project->id), 'repository' => $project->repository_url, 'commit' => $commit,
            'composer' => self::Composer, 'bun' => self::Bun, ...$branch === null ? [] : ['branch' => $branch]];
    }

    /** @return array{url:string,sha256:string} the pinned agent build of the host's platform, which every task VM on it runs */
    private function agent(Node $host): array
    {
        $artifact = $this->delivery->artifacts()[AgentDelivery::key($host)] ?? null;
        if ($artifact === null || ! isset($artifact['url'])) {
            throw new ResourceOperationException('task_vm.converge_unavailable', 'The agent pin names no download for this platform.', 409);
        }

        return ['url' => $artifact['url'], 'sha256' => $artifact['sha256']];
    }
}
