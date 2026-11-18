<?php

declare(strict_types=1);

namespace App\Spike\StateMachine;

use App\Infrastructure\Certificates\GatewayLeafCertificateSigner;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Models\Operation;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * SPIKE: route.create as a state machine on generic primitives, from the very intent the Gateway already admitted and
 * rendered for Swamp (`input.route_create`). Per leaf: CSR on its Node → sign on the Gateway → certificate, links and
 * account copy on the Node. Per serving Node: the whole Caddyfile (validated, swapped, reloaded). Per DNS Node: records,
 * catalog, restart — from the first second, since DNS needs no certificate. Then a TLS probe from the Gateway, and the
 * Route's own projection. Content travels by SHA-256: the Node fetches it and refuses any other bytes.
 */
final class RouteCreateFlow
{
    /** Lists this spike does not carry; a Route that needs them stays on Swamp. */
    private const array Unsupported = ['acl', 'links', 'php_files', 'url_lines', 'env_files', 'ingress'];

    public static function start(Operation $route): Operation
    {
        $steps = self::steps($route->input['route_create']);

        // Spike fault injection for the comparison: e.g. {"drop_receipt": ["caddy-3"]}.
        $faults = (array) \Illuminate\Support\Facades\Cache::get('spike:route-faults', []);
        $engine = Engine::start($steps, $faults, self::class, ['route_operation' => $route->id]);
        // The Route operation's run is the engine operation, as it would be a Swamp run.
        $route->refresh()->forceFill(['state' => [...($route->state ?? []), 'swamp_run_id' => 'spike-sm-'.$engine->id]])->save();

        return $engine;
    }

    /** The Route's steps from its admitted intent; an instance.create runs the same steps beside its container. */
    public static function steps(array $intent): array
    {
        foreach (self::Unsupported as $list) {
            if (! empty($intent[$list])) {
                throw new RuntimeException("spike: route intent has {$list}");
            }
        }
        $steps = [];
        $site = []; // per Node: the steps its Caddy publication waits for
        foreach ($intent['leaf_indexes'] ?? [] as $index) {
            $leaf = $intent['leaves'][(int) $index];
            $n = (int) $leaf['node_id'];
            $steps["csr-$index"] = ['node' => $n, 'action' => 'tls.csr', 'after' => [], 'budget_s' => 60];
            $steps["sign-$index"] = ['node' => 0, 'action' => 'gateway.sign', 'after' => ["csr-$index"], 'budget_s' => 30];
            $steps["cert-$index"] = ['node' => $n, 'action' => 'file.put', 'after' => ["sign-$index"], 'budget_s' => 60];
            $steps["current-$index"] = ['node' => $n, 'action' => 'file.link', 'after' => ["cert-$index"], 'budget_s' => 60];
            $steps["copy-$index"] = ['node' => $n, 'action' => 'file.install', 'after' => ["cert-$index"], 'budget_s' => 60];
            $steps["acurrent-$index"] = ['node' => $n, 'action' => 'file.link', 'after' => ["copy-$index"], 'budget_s' => 60];
            $site[$n] ??= [];
            array_push($site[$n], "current-$index", "acurrent-$index");
        }
        foreach ($intent['trust'] ?? [] as $n) {
            $steps["trust-$n"] = ['node' => (int) $n, 'action' => 'ca.trust', 'after' => [], 'budget_s' => 60];
            $site[(int) $n][] = "trust-$n";
        }
        foreach ($intent['caddy'] ?? [] as $row) {
            $n = (int) $row['node_id'];
            $steps["caddy-$n"] = ['node' => $n, 'action' => 'caddy.config', 'after' => $site[$n] ?? [], 'budget_s' => 120, 'resend_s' => 10];
        }
        foreach ($intent['dns'] ?? [] as $row) {
            $n = (int) $row['node_id'];
            $steps["records-$n"] = ['node' => $n, 'action' => 'file.put', 'after' => [], 'budget_s' => 60];
            $steps["catalog-$n"] = ['node' => $n, 'action' => 'file.put', 'after' => [], 'budget_s' => 60];
            $steps["restart-$n"] = ['node' => $n, 'action' => 'systemd.restart', 'after' => ["records-$n", "catalog-$n"], 'budget_s' => 60];
        }
        foreach ($intent['probes'] ?? [] as $i => $probe) {
            $n = (int) $probe['node_id'];
            $steps["probe-$i"] = ['node' => 0, 'action' => 'gateway.probe', 'after' => isset($steps["caddy-$n"]) ? ["caddy-$n"] : [], 'budget_s' => 30];
        }

        return $steps;
    }

    /** The typed input of a Node step, from the intent and the results so far. */
    public static function input(string $name, Operation $engine): array
    {
        [$kind, $key] = explode('-', $name, 2);
        $intent = self::intent($engine);
        $leaf = is_numeric($key) && isset($intent['leaves'][(int) $key]) ? $intent['leaves'][(int) $key] : null;
        $home = fn (int $n): string => '/home/'.$intent['nodes'][(string) $n]['account'];
        $state = $engine->state['steps'];
        $node = (int) $engine->input['steps'][$name]['node'];
        $blob = static fn (string $content): array => \App\Spike\Nats\Bus::putContent($node, $content);

        return match ($kind) {
            'csr' => ['directory' => $leaf['directory'], 'domain' => $leaf['domain']],
            'cert' => ['path' => $leaf['directory'].'/cert.pem', 'blob' => $blob(self::pem($state["sign-$key"]['output'])),
                'owner' => 'root', 'group' => 'caddy', 'mode' => '0644'],
            'current' => ['path' => $leaf['current'], 'target' => $leaf['target'], 'owner' => 'root'],
            'copy' => ['sources' => [$leaf['directory'].'/key.pem', $leaf['directory'].'/cert.pem'],
                'directory' => $home((int) $leaf['node_id']).'/.orbit/certificates/'.$leaf['scope'].'/'.$leaf['target'],
                'owner' => $leaf['user'], 'group' => $intent['nodes'][(string) $leaf['node_id']]['account'], 'mode' => '0600'],
            'acurrent' => ['path' => $home((int) $leaf['node_id']).'/.orbit/certificates/'.$leaf['scope'].'/current',
                'target' => $leaf['target'], 'owner' => $leaf['user']],
            'trust' => ['blob' => $blob($intent['root_certificate'])],
            'caddy' => ['blob' => $blob(self::row($intent['caddy'], (int) $key)['content'])],
            'records' => ['path' => '/etc/dnsmasq.d/orbit-records.conf', 'blob' => $blob(self::row($intent['dns'], (int) $key)['records']),
                'owner' => 'root', 'group' => 'root', 'mode' => '0644'],
            'catalog' => ['path' => '/var/lib/orbit/private-dns/catalog.json', 'blob' => $blob(self::row($intent['dns'], (int) $key)['catalog']),
                'owner' => 'root', 'group' => 'root', 'mode' => '0644'],
            'restart' => ['units' => ['dnsmasq', 'orbit-private-dns.service']],
        };
    }

    /** Gateway steps: sign a leaf's CSR with the Orbit root, and probe a published site over TLS. */
    public static function local(string $name, Operation $engine): string
    {
        [$kind, $key] = explode('-', $name, 2);
        $intent = self::intent($engine);
        if ($kind === 'sign') {
            $leaf = $intent['leaves'][(int) $key];
            $root = app(LeafCertificateSigner::class)->rootCertificate();
            if (! hash_equals((string) $leaf['root_hash'], hash('sha256', $root))) {
                throw new RuntimeException('certificate.intent_mismatch');
            }

            return app(GatewayLeafCertificateSigner::class)->sign($leaf['domain'], $engine->state['steps']["csr-$key"]['output'], $root);
        }
        $probe = $intent['probes'][(int) $key];
        $ca = tempnam(sys_get_temp_dir(), 'spike-ca');
        file_put_contents($ca, $intent['root_certificate']);
        try {
            $socket = @stream_socket_client("ssl://{$probe['address']}:443", $errno, $error, 10, STREAM_CLIENT_CONNECT,
                stream_context_create(['ssl' => ['peer_name' => $probe['domain'], 'verify_peer' => true, 'verify_peer_name' => true,
                    'cafile' => $ca, 'SNI_enabled' => true]]));
            if ($socket === false) {
                throw new RuntimeException("route.probe_failed: {$error}");
            }
            fclose($socket);

            return 'verified';
        } finally {
            @unlink($ca);
        }
    }

    /** The Route operation gets its result through its own projection, as after a Swamp run. */
    public static function finished(Operation $engine, string $status): void
    {
        $route = Operation::query()->findOrFail($engine->input['context']['route_operation']);
        $failed = collect($engine->state['steps'])->filter(fn (array $s): bool => $s['status'] === 'failed')->keys()->first();
        $run = 'spike-sm-'.$engine->id;
        $at = fn (int $ms): string => \Illuminate\Support\Carbon::createFromTimestampMs($ms)->toIso8601ZuluString('millisecond');
        $times = ['started_at' => $at($engine->state['started_ms']), 'finished_at' => $at($engine->state['finished_ms'] ?? Engine::nowMs())];
        app(NativeRouteConvergenceProjection::class)->apply($route, $status === 'succeeded'
            ? ['status' => 'succeeded', 'run_id' => $run, ...$times]
            : ['status' => 'failed', 'run_id' => $run, ...$times, 'error_code' => 'route.create_failed', 'failure' => ['failed_step' => $failed]]);
    }

    private static function intent(Operation $engine): array
    {
        return Operation::query()->findOrFail($engine->input['context']['route_operation'])->input['route_create'];
    }

    private static function row(array $rows, int $node): array
    {
        foreach ($rows as $row) {
            if ((int) $row['node_id'] === $node) {
                return $row;
            }
        }
        throw new RuntimeException("spike: no row for node {$node}");
    }

    private static function pem(string $signed): string
    {
        $begin = '-----BEGIN CERTIFICATE-----';

        return $begin.explode($begin, $signed, 2)[1];
    }

}
