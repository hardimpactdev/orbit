<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\SwampNode;
use App\Infrastructure\AppDev\PrivateDnsPublication;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * The operation-route-create inputs (flow-tighten rule 22): every Node change is an official cfgmgmt behavior or
 * one command, so this plan gives each step its ready list. The Gateway renders whole files from the admitted
 * capture (NativeRouteLifecycleInput); no step merges, reads back or checks ownership on a Node.
 */
final readonly class NativeRouteCreatePlan
{
    private const string CaddyCertificates = '/etc/caddy/orbit-certificates';

    /** @param array<string,mixed> $lifecycle
     * @return array<string,mixed>
     */
    public function forCreate(Route $route, array $lifecycle): array
    {
        $plan = $this->build($route, $lifecycle);

        return [...$plan, 'probe_ssh' => $plan['probes'] === [] ? (object) [] : app(SwampSshExecutionInput::class)->forNode(SwampNode::node())];
    }

    /** @param array<string,mixed> $lifecycle
     * @return array<string,mixed>
     */
    public function build(Route $route, array $lifecycle): array
    {
        $plan = ['lan' => [], 'ingress_ports' => [], 'acl' => [], 'links' => [], 'php_files' => [], 'php_tests' => [], 'php_reloads' => [],
            'leaves' => [], 'caddy' => [], 'url_lines' => [], 'env_files' => [], 'probes' => [], 'ingress' => [], 'dns' => [], 'awake' => []];
        $trust = [];
        $root = '';
        $nodes = [];
        if ($lifecycle['effectful'] !== true) {
            return $this->result($route, $plan, $trust, $root, $nodes);
        }
        $route->loadMissing(['cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
        $forward = ValidatedData::object(ValidatedData::object($lifecycle['route_mutations'])['forward']);
        $plan['awake'] = RouteExecutionProof::objects($lifecycle['awake'] ?? []);
        $preparation = ValidatedData::object($lifecycle['preparation']);
        $renders = is_array($lifecycle['renders'] ?? null) ? $lifecycle['renders'] : [];
        $ingressId = $lifecycle['public_activated'] ? $route->cluster?->ingressAssignment?->node_id : null;
        $touched = [];
        foreach ($plan['awake'] as $marker) {
            $touched[ValidatedData::integer($marker['node_id'])] = true;
        }

        foreach (RouteExecutionProof::objects($lifecycle['workloads']) as $workload) {
            $nodeId = ValidatedData::integer($workload['node_id']);
            $selected = ValidatedData::object($workload['selected']);
            // The workload capture carries its Caddy access commands and release links (RouteWebRootCommands).
            $acl = RouteExecutionProof::objects($selected['acl']);
            $links = RouteExecutionProof::objects($selected['links']);
            if ($acl !== [] || $links !== []) {
                $plan['acl'] = [...$plan['acl'], ...$acl];
                $plan['links'] = [...$plan['links'], ...$links];
                $touched[$nodeId] = true;
            }
            if (is_array($selected['runtime'] ?? null)) {
                $runtime = ValidatedData::object($selected['runtime']);
                $user = ValidatedData::string($runtime['user']);
                $directory = '/etc/orbit/php-fpm/'.$user;
                $version = ValidatedData::string($runtime['version']);
                $plan['php_files'][] = ['node_id' => $nodeId, 'path' => $directory.'/generated/pool.conf', 'content' => $runtime['pool'], 'owner' => 'root'];
                $plan['php_tests'][] = ['node_id' => $nodeId, 'argv' => ['/usr/bin/env', 'PHP_INI_SCAN_DIR=/etc/php/'.$version.'/fpm/conf.d:'.$directory.'/generated',
                    '/usr/sbin/php-fpm'.$version, '-t', '--fpm-config', $directory.'/generated/php-fpm.conf']];
                $plan['php_reloads'][] = ['node_id' => $nodeId, 'unit' => 'orbit-'.$user.'-php'.$version.'-fpm.service'];
                $touched[$nodeId] = true;
            }
        }

        foreach (RouteExecutionProof::objects($forward['publications']) as $publication) {
            $nodeId = ValidatedData::integer($publication['node_id']);
            $before = ValidatedData::object($publication['before']);
            $after = ValidatedData::object($publication['after']);
            $render = is_array($renders[(string) $nodeId] ?? null) ? $renders[(string) $nodeId] : null;
            if ($render === null) {
                continue;
            }
            if ($before['php'] !== $after['php'] && is_array($render['php'])) {
                $this->sharedPools($plan, $nodeId, $render['php']);
                $touched[$nodeId] = true;
            }
            if ($publication['serves_routes'] === true && $before['caddy'] !== $after['caddy'] && is_string($render['caddy'])) {
                $plan[$nodeId === $ingressId ? 'ingress' : 'caddy'][] = ['node_id' => $nodeId, 'content' => $render['caddy']];
                $touched[$nodeId] = true;
            }
            // A domain inside the TLD changes no record: private DNS already answers the whole TLD.
            $dnsArtifacts = ValidatedData::object(ValidatedData::object($lifecycle['route_mutations'])['artifacts'])['dns'] ?? [];
            $artifact = static fn (mixed $index): mixed => is_int($index) && is_array($dnsArtifacts) ? ($dnsArtifacts[$index] ?? null) : null;
            if ($after['dns'] !== null && ! PrivateDnsPublication::same($artifact($after['dns']), $artifact($before['dns'])) && is_array($render['dns'])) {
                $plan['dns'][] = ['node_id' => $nodeId, 'dns' => ValidatedData::object($render['dns'])];
                $touched[$nodeId] = true;
            }
        }

        $leaves = [];
        foreach ([...RouteExecutionProof::objects($preparation['certificates']), ...RouteExecutionProof::objects($forward['certificates'])] as $leaf) {
            $leaves[ValidatedData::integer($leaf['node_id']).'/'.ValidatedData::string($leaf['scope'])] = $leaf;
        }
        foreach (array_values($leaves) as $leaf) {
            $row = self::leaf($leaf);
            $plan['leaves'][] = $row;
            $trust[$row['node_id']] = $row['node_id'];
            $touched[$row['node_id']] = true;
        }
        if ($plan['leaves'] !== []) {
            $root = app(LeafCertificateSigner::class)->rootCertificate();
        }

        foreach ([...RouteExecutionProof::objects($preparation['lan']), ...RouteExecutionProof::objects($forward['lan'])] as $row) {
            foreach (RouteExecutionProof::objects($row['rules']) as $rule) {
                $entry = ['node_id' => ValidatedData::integer($row['node_id']), 'port' => ValidatedData::integer($rule['port']),
                    'protocol' => ValidatedData::string($rule['protocol']), 'source' => ValidatedData::string($rule['source'])];
                if ($rule['ensure'] === 'present' && ! in_array($entry, $plan['lan'], true)) {
                    $plan['lan'][] = $entry;
                    $touched[$entry['node_id']] = true;
                }
            }
        }
        foreach (RouteExecutionProof::objects($forward['ingress']) as $row) {
            foreach (RouteExecutionProof::objects($row['rules']) as $rule) {
                if ($rule['ensure'] === 'present') {
                    $plan['ingress_ports'][] = ['node_id' => ValidatedData::integer($row['node_id']), 'port' => ValidatedData::integer($rule['port']), 'ensure' => 'present'];
                    $touched[ValidatedData::integer($row['node_id'])] = true;
                }
            }
        }
        // APP_URL is one line (cfgmgmt line); a whole production .env comes from the vault (cfgmgmt file, Q-SECRET).
        foreach (RouteExecutionProof::objects($forward['url_writes']) as $write) {
            $nodeId = ValidatedData::integer($write['node_id']);
            $input = ValidatedData::object($write['input']);
            if ($write['name'] === 'instance.write-environment') {
                $plan['env_files'][] = ['node_id' => $nodeId, 'path' => rtrim(ValidatedData::string($input['base']), '/').'/'.ValidatedData::string($input['file']),
                    'owner' => ValidatedData::string($input['user']), 'secret_field' => ValidatedData::string($write['secret_field'])];
            } else {
                $directory = ValidatedData::string($input['directory']);
                if ($write['name'] === 'instance.write-directory-url') {
                    $directory = rtrim(ValidatedData::string($input['home']), '/').'/env'.($directory === '' ? '' : '/'.$directory);
                }
                $plan['url_lines'][] = ['node_id' => $nodeId, 'path' => $directory.'/.env', 'line' => 'APP_URL='.ValidatedData::string($input['url']),
                    'owner' => ValidatedData::string($input['user'] ?? $input['owner'])];
            }
            $touched[$nodeId] = true;
        }

        // Swamp probes from 209 each serving Node, and the Router of a Cluster Route.
        $router = $route->cluster?->routerAssignment?->node;
        $entries = Node::query()->whereKey(array_column($plan['caddy'], 'node_id'))->orderBy('id')->get()->all();
        if ($router instanceof Node && ! in_array($router->id, array_map(static fn (Node $node): int => $node->id, $entries), true)) {
            $entries[] = $router;
        }
        foreach ($entries as $node) {
            $address = app(RouteRenderContext::class)->wireguardIp($node);
            if (is_string($address)) {
                $plan['probes'][] = ['node_id' => $node->id, 'address' => $address, 'domain' => $route->domain];
            }
        }

        foreach (Node::query()->whereKey(array_keys($touched))->get() as $node) {
            $connection = app(NativeCaddyPublicationInput::class)->connectionForNode($node);
            $nodes[(string) $node->id] = ['host' => $connection['connection']['nodeHost'], 'user' => $connection['connection']['nodeUser'],
                'port' => $connection['connection']['nodePort'], 'identity_file' => $connection['connection']['nodeIdentityFile'], 'host_keys' => $connection['host_keys'],
                'account' => app(RouteRenderContext::class)->user($node)];
        }

        return $this->result($route, $plan, array_values($trust), $root, $nodes);
    }

    /**
     * One admitted leaf as the CSR, sign and install steps read it.
     *
     * @param  array<string,mixed>  $leaf
     * @return array{node_id:int,scope:string,domain:string,root_hash:string,directory:string,current:string,target:string,user:string}
     */
    public static function leaf(array $leaf): array
    {
        $nodeId = ValidatedData::integer($leaf['node_id']);
        $scope = ValidatedData::string($leaf['scope']);
        $version = ValidatedData::string($leaf['version']);

        // The account keeps its own copy for development servers; the run reads the account's home on the Node.
        return ['node_id' => $nodeId, 'scope' => $scope, 'domain' => ValidatedData::string($leaf['domain']), 'root_hash' => ValidatedData::string($leaf['root_hash']),
            'directory' => self::CaddyCertificates.'/'.$scope.'/versions/'.$version, 'current' => self::CaddyCertificates.'/'.$scope.'/current', 'target' => 'versions/'.$version,
            'user' => app(RouteRenderContext::class)->user(Node::query()->findOrFail($nodeId))];
    }

    /**
     * @param  array<string,list<array<string,mixed>>>  $plan
     * @param  list<int>  $trust
     * @param  array<int|string,array<string,mixed>>  $nodes
     * @return array<string,mixed>
     */
    private function result(Route $route, array $plan, array $trust, string $root, array $nodes): array
    {
        ksort($nodes);

        // Each Node with certificate, web root, pool, site or environment work gets one "Install cert, publish site" child.
        $sites = $trust;
        foreach (['leaves', 'acl', 'links', 'php_files', 'caddy', 'url_lines', 'env_files', 'awake'] as $list) {
            $sites = [...$sites, ...array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), $plan[$list])];
        }
        $sites = array_values(array_unique($sites));
        sort($sites);

        // forEach over integer indexes keys each iteration by its index, so a later step can read an earlier one's output.
        return ['route_id' => $route->id, 'domain' => $route->domain, ...$plan, 'leaf_indexes' => array_keys($plan['leaves']), 'trust' => $trust, 'root_certificate' => $root,
            'site_nodes' => $sites, 'nodes' => $nodes === [] ? (object) [] : $nodes];
    }

    /**
     * The shared development PHP-FPM pools of a Node, whole. The run puts the account's gid and home in place.
     *
     * @param  array<string,list<array<string,mixed>>>  $plan
     * @param  array<array-key,mixed>  $php
     */
    private function sharedPools(array &$plan, int $nodeId, array $php): void
    {
        $configurations = $php['configurations'] ?? null;
        if (! $configurations instanceof \stdClass) {
            throw new \LogicException('Shared PHP capture requires a configuration object.');
        }
        foreach (ValidatedData::stringMap(get_object_vars($configurations)) as $version => $content) {
            $version = (string) $version;
            $plan['php_files'][] = ['node_id' => $nodeId, 'path' => '/etc/php/'.$version.'/fpm/pool.d/orbit-scopes.conf', 'content' => ValidatedData::string($content), 'owner' => 'root'];
            $plan['php_tests'][] = ['node_id' => $nodeId, 'argv' => ['/usr/sbin/php-fpm'.$version, '-t']];
            $plan['php_reloads'][] = ['node_id' => $nodeId, 'unit' => 'php'.$version.'-fpm.service'];
        }
    }
}
