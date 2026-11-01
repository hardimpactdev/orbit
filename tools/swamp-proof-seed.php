<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Fixture records only. The topology image and its verifier own actual runtime readiness. */
final readonly class SwampProofFixtureSeeder
{
    /** @param array<string, mixed> $input
     * @return array{seeded: true, cluster_id: int, project_id: int, nodes: array<string, int>}
     */
    public function execute(array $input): array
    {
        $facts = Validator::make($input, [
            'cluster' => ['required', 'array:name,tld'],
            'cluster.name' => ['required', 'string', 'max:255'],
            'cluster.tld' => ['nullable', 'string', 'max:255'],
            'nodes' => ['required', 'array', 'min:2'],
            'nodes.*' => ['required', 'array:key,name,platform,architecture,user,home,public_address,public_ssh_port,wireguard_ip,wireguard_public_key,ssh_host_key_type,ssh_host_key,roles,tld'],
            'nodes.*.key' => ['required', 'string', 'distinct', 'regex:/\A[a-z][a-z0-9-]*\z/D'],
            'nodes.*.name' => ['required', 'string', 'distinct', 'max:255'],
            'nodes.*.platform' => ['required', 'in:linux'],
            'nodes.*.architecture' => ['required', 'in:x86_64'],
            'nodes.*.user' => ['required', 'in:orbit'],
            'nodes.*.home' => ['required', 'in:/home/orbit'],
            'nodes.*.public_address' => ['required', 'ipv4', 'distinct'],
            'nodes.*.public_ssh_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'nodes.*.wireguard_ip' => ['required', 'ipv4', 'distinct'],
            'nodes.*.wireguard_public_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9+\/]{43}=\z/D'],
            'nodes.*.ssh_host_key_type' => ['required', 'in:ssh-ed25519'],
            'nodes.*.ssh_host_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9+\/]+={0,2}\z/D'],
            'nodes.*.roles' => ['present', 'array'],
            'nodes.*.roles.*' => ['required', Rule::enum(RoleName::class)],
            'nodes.*.tld' => ['nullable', 'string', 'max:255'],
            'project' => ['required', 'array:name,slug,type,repository_url,default_branch,root,source_access'],
            'project.name' => ['required', 'string', 'max:255'],
            'project.slug' => ['required', 'string', 'regex:/\A[a-z][a-z0-9-]*\z/D'],
            'project.type' => ['required', 'in:node-package'],
            'project.repository_url' => ['required', 'in:https://repositories.orbit/seed.git'],
            'project.default_branch' => ['required', 'string'],
            'project.root' => ['required', 'in:.'],
            'project.source_access' => ['required', 'in:github_app'],
        ])->validate();
        $keys = array_column($facts['nodes'], 'key');
        if (! in_array('gateway', $keys, true) || ! in_array('operator', $keys, true)) {
            throw new RuntimeException('proof.seed_missing_core_nodes');
        }
        foreach ($facts['nodes'] as $node) {
            $bytes = base64_decode($node['ssh_host_key'], strict: true);
            if ($bytes === false || strlen($bytes) !== 51 || substr($bytes, 0, 19) !== pack('N', 11).'ssh-ed25519'.pack('N', 32)) {
                throw new RuntimeException('proof.seed_invalid_ssh_host_key');
            }
            if ($node['key'] === 'gateway' && ! in_array('gateway', $node['roles'], true)) {
                throw new RuntimeException('proof.seed_missing_gateway_role');
            }
        }

        return DB::transaction(function () use ($facts): array {
            foreach ([Node::class, Cluster::class, Project::class, NodeRole::class, Operation::class, Instance::class, Task::class] as $model) {
                if ($model::withoutGlobalScopes()->exists()) {
                    throw new RuntimeException('proof.seed_database_not_empty');
                }
            }
            $cluster = Cluster::query()->create($facts['cluster']);
            $nodes = [];
            foreach ($facts['nodes'] as $fact) {
                $bytes = base64_decode($fact['ssh_host_key'], strict: true);
                $node = Node::query()->create([
                    'cluster_id' => $cluster->id, 'name' => $fact['name'],
                    'platform' => $fact['platform'], 'architecture' => $fact['architecture'], 'user' => $fact['user'],
                    'public_ssh_host' => $fact['public_address'], 'lan_ip' => $fact['public_address'],
                    'public_ssh_port' => $fact['public_ssh_port'] ?? 22, 'wireguard_ip' => $fact['wireguard_ip'],
                    'wireguard_public_key' => $fact['wireguard_public_key'], 'ssh_host_key_type' => $fact['ssh_host_key_type'],
                    'ssh_host_key' => $fact['ssh_host_key'],
                    'ssh_host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', $bytes, binary: true)), '='),
                    'tld' => $fact['tld'] ?? null, 'status' => 'active',
                ]);
                foreach ($fact['roles'] as $role) {
                    $node->roles()->create(['cluster_id' => in_array($role, ['router', 'ingress'], true) ? $cluster->id : null, 'role' => $role, 'status' => 'active']);
                }
                $nodes[$fact['key']] = $node;
            }
            $nodes['operator']->accessibleNodes()->attach($nodes['gateway']->id);
            $project = Project::query()->create([...$facts['project'], 'task_workspace_routed' => false, 'review_and_merge' => false]);

            return ['seeded' => true, 'cluster_id' => $cluster->id, 'project_id' => $project->id,
                'nodes' => array_map(static fn (Node $node): int => $node->id, $nodes)];
        });
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $checkout = realpath($argv[1] ?? '');
        if ($checkout === false || ! is_file($checkout.'/bootstrap/app.php')) {
            throw new RuntimeException('proof.seed_checkout_missing');
        }
        require $checkout.'/vendor/autoload.php';
        $app = require $checkout.'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $input = json_decode(stream_get_contents(STDIN), associative: true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($input) || array_is_list($input)) {
            throw new RuntimeException('proof.seed_input_invalid');
        }
        echo json_encode(new SwampProofFixtureSeeder()->execute($input), JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (Throwable $error) {
        fwrite(STDERR, json_encode(['error' => $error->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL);
        exit(1);
    }
}
