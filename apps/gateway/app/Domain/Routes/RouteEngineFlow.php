<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Certificates\SignDirectCertificatesAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;
use RuntimeException;

/**
 * `route:create` on the state machine (ADR 0271; docs/cli/route/create.mmd). It reads the plan the Gateway admitted
 * (`route_create`, NativeRouteCreatePlan) and runs it as typed steps:
 *
 * - `csr-<i>`: `tls.ensure_csr` on the leaf's Node, which answers a request for a key that never leaves it;
 * - `sign-<i>`: the Gateway signs the request against the admitted leaf, as `operation:direct:certificate-sign` does;
 * - `site-<node>`: `route.publish_site` on each Node with Route work;
 * - `probe-<i>`: the Gateway's TLS handshake with each serving Node, by its WireGuard address and the Route's name.
 *
 * A Route that needs private DNS, the Ingress edge, a LAN rule, a production `.env` or awake markers starts on Swamp
 * ([EngineUnsupported]). Step labels map onto the chart's, so a failure records the same `failed_step` and error code.
 */
final readonly class RouteEngineFlow implements EngineDefinition
{
    /** Each step prefix's chart label (NativeRouteConvergenceProjection::StepCodes). */
    private const array Labels = ['csr' => 'Make a CSR on the Node', 'sign' => 'Sign the CSR', 'site' => 'Install cert, publish site',
        'probe' => 'Probe the site over TLS'];

    private const array Unsupported = ['lan', 'ingress', 'ingress_ports', 'dns', 'env_files', 'awake'];

    public function __construct(private NativeRouteConvergenceProjection $projection, private SignDirectCertificatesAction $signer,
        private LeafCertificateSigner $root) {}

    public function steps(Operation $operation): array
    {
        $plan = self::plan($operation);
        foreach (self::Unsupported as $list) {
            if (($plan[$list] ?? []) !== []) {
                throw new EngineUnsupported("route.create with {$list} runs on Swamp.");
            }
        }
        $steps = $signs = $sites = [];
        foreach (RouteExecutionProof::objects($plan['leaves']) as $index => $leaf) {
            $node = ValidatedData::integer($leaf['node_id']);
            $steps[] = new StepSpec("csr-{$index}", $node, 'tls.ensure_csr', (string) $node, budgetMs: 60_000, resendMs: 15_000);
            $steps[] = $signs[] = new StepSpec("sign-{$index}", 0, 'certificate.sign', 'gateway', ["csr-{$index}"], budgetMs: 30_000);
        }
        $after = array_map(static fn (StepSpec $step): string => $step->name, $signs);
        foreach (ValidatedData::integerList($plan['site_nodes']) as $node) {
            // Shapes the executor cannot take send the Route to Swamp now, before anything starts.
            $this->siteInput($plan, $node, static fn (): string => "-----BEGIN CERTIFICATE-----\n");
            $steps[] = $sites[] = new StepSpec("site-{$node}", $node, 'route.publish_site', (string) $node, $after, budgetMs: 240_000, resendMs: 30_000);
        }
        $after = array_map(static fn (StepSpec $step): string => $step->name, $sites);
        foreach (array_keys(RouteExecutionProof::objects($plan['probes'])) as $index) {
            $steps[] = new StepSpec("probe-{$index}", 0, 'route.probe', 'gateway', $after, budgetMs: 60_000);
        }

        return $steps;
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        $plan = self::plan($operation);
        [$kind, $index] = explode('-', $step, 2);

        return match ($kind) {
            'csr' => self::csrInput(RouteExecutionProof::objects($plan['leaves'])[(int) $index]),
            'site' => $this->siteInput($plan, (int) $index, static fn (int $leaf): string => $run->output("sign-{$leaf}")
                ?? throw new LogicException("Leaf {$leaf} has no signed certificate.")),
            default => throw new LogicException("Step [{$step}] is a Gateway step."),
        };
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        $plan = self::plan($operation);
        [$kind, $index] = explode('-', $step, 2);

        return match ($kind) {
            'sign' => $this->sign($operation, RouteExecutionProof::objects($plan['leaves'])[(int) $index], $run->output("csr-{$index}") ?? '', $run),
            'probe' => $this->probe(RouteExecutionProof::objects($plan['probes'])[(int) $index]),
            default => throw new LogicException("Step [{$step}] runs on a Node."),
        };
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => $failed->error_code,
                'failure' => ['failed_step' => self::chartStep($failed->name), 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            default => ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => []],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    /** A step's name as the chart's iteration, such as `site-3` → `Install cert, publish site-3`, for NativeFailedStep. */
    public static function chartStep(string $name): string
    {
        [$kind, $index] = explode('-', $name, 2);

        return (self::Labels[$kind] ?? throw new LogicException("Step [{$name}] has no chart label.")).'-'.$index;
    }

    /**
     * @param  array<string, mixed>  $leaf
     * @return array{directory: string, domain: string}
     */
    private static function csrInput(array $leaf): array
    {
        return ['directory' => ValidatedData::string($leaf['directory']), 'domain' => ValidatedData::string($leaf['domain'])];
    }

    /** @return array<string, mixed> */
    private static function plan(Operation $operation): array
    {
        return ValidatedData::object($operation->input['route_create'] ?? []);
    }

    /**
     * One Node's `route.publish_site` input. `$signed` gives a leaf's signed certificate by its index; content travels as
     * blobs. Each access row must be one of the shapes RouteWebRootCommands renders, and each pool one of the two pool kinds.
     *
     * @param  array<string, mixed>  $plan
     * @param  callable(int): string  $signed
     * @return array<string, mixed>
     */
    private function siteInput(array $plan, int $node, callable $signed): array
    {
        $mine = static fn (string $list): array => array_values(array_filter(RouteExecutionProof::objects($plan[$list] ?? []),
            static fn (array $row): bool => ValidatedData::integer($row['node_id']) === $node));
        $nodes = ValidatedData::object($plan['nodes']);
        if (! is_array($nodes[(string) $node] ?? null)) {
            throw new EngineUnsupported("Node {$node} has no connection.");
        }
        $account = ValidatedData::string(ValidatedData::object($nodes[(string) $node])['account']);
        $leaves = [];
        foreach (RouteExecutionProof::objects($plan['leaves']) as $index => $leaf) {
            if (ValidatedData::integer($leaf['node_id']) !== $node) {
                continue;
            }
            if (ValidatedData::string($leaf['user']) !== $account || preg_match('#\Aversions/([0-9a-f]{16})\z#', ValidatedData::string($leaf['target']), $version) !== 1) {
                throw new EngineUnsupported('A leaf outside the account or version shape runs on Swamp.');
            }
            $leaves[] = ['scope' => ValidatedData::string($leaf['scope']), 'version' => $version[1], 'certificate' => ['blob' => ['content' => self::leafPem($signed($index))]]];
        }
        $caddy = $mine('caddy');
        $trusted = in_array($node, ValidatedData::integerList($plan['trust']), true);

        return [
            'account' => $account,
            'acl' => array_map(self::access(...), $mine('acl')),
            'url_lines' => array_map(static fn (array $row): array => ['path' => ValidatedData::string($row['path']),
                'url' => str_starts_with(ValidatedData::string($row['line']), 'APP_URL=') ? substr(ValidatedData::string($row['line']), 8)
                    : throw new EngineUnsupported('A URL line outside APP_URL runs on Swamp.')], $mine('url_lines')),
            'links' => array_map(static fn (array $row): array => ['path' => ValidatedData::string($row['path']), 'target' => ValidatedData::string($row['target']),
                'owner' => ValidatedData::string($row['owner'])], $mine('links')),
            'php' => self::pools($mine('php_files'), $mine('php_tests'), $mine('php_reloads')),
            'leaves' => $leaves,
            'root_certificate' => $trusted ? ['blob' => ['content' => ValidatedData::string($plan['root_certificate'])]] : null,
            'caddy' => $caddy === [] ? null : ['blob' => ['content' => ValidatedData::string(end($caddy)['content'])]],
        ];
    }

    /**
     * An access row as its typed kind. The kind's command, rebuilt as the executor builds it, must equal the admitted one.
     *
     * @param  array<string, mixed>  $row
     * @return array{kind: string, user: ?string, paths: list<string>, optional_paths: list<string>}
     */
    private static function access(array $row): array
    {
        $argv = ValidatedData::stringList($row['argv']);
        $user = null;
        if (array_slice($argv, 0, 2) === ['/usr/sbin/runuser', '-u'] && array_slice($argv, 3, 4) === ['--', '/usr/bin/env', '-C', '/']) {
            $user = $argv[2];
            $argv = array_slice($argv, 7);
        }
        $paths = ($argv[0] ?? '') === '/usr/bin/find' ? array_slice($argv, 2, -9) : array_slice($argv, (int) array_search('--', $argv, true) + 1);
        foreach (['deny_defaults', 'deny', 'passage', 'grant_defaults', 'grant'] as $kind) {
            if (self::accessArgv($kind, $paths) === $argv && ($kind === 'passage') === ($user === null)) {
                return ['kind' => $kind, 'user' => $user, 'paths' => $paths, 'optional_paths' => ValidatedData::stringList($row['optional_paths'] ?? [])];
            }
        }
        throw new EngineUnsupported('An access command outside the known shapes runs on Swamp.');
    }

    /**
     * The executor's command for an access kind, after the `runuser` prefix (orbit-executor-route `access_argv`).
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function accessArgv(string $kind, array $paths): array
    {
        return match ($kind) {
            'deny_defaults' => ['/usr/bin/find', '-P', ...$paths, '-type', 'd', '-exec', '/usr/bin/setfacl', '-m', 'd:u:caddy:---', '--', '{}', '+'],
            'grant_defaults' => ['/usr/bin/find', '-P', ...$paths, '-type', 'd', '-exec', '/usr/bin/setfacl', '-m', 'd:u:caddy:r-x', '--', '{}', '+'],
            'deny' => ['/usr/bin/setfacl', '-P', '-R', '-n', '-m', 'u:caddy:---', '--', ...$paths],
            'grant' => ['/usr/bin/setfacl', '-P', '-R', '-n', '-m', 'u:caddy:r-X', '--', ...$paths],
            'passage' => ['/usr/bin/setfacl', '-m', 'u:caddy:--x', '--', ...$paths],
            default => throw new LogicException("Unknown access kind [{$kind}]."),
        };
    }

    /**
     * Pools as `{version, user}`: the executor derives each path, test and unit, which must equal the admitted ones.
     *
     * @param  list<array<string, mixed>>  $files
     * @param  list<array<string, mixed>>  $tests
     * @param  list<array<string, mixed>>  $reloads
     * @return list<array{version: string, user: ?string, content: array{blob: array{content: string}}}>
     */
    private static function pools(array $files, array $tests, array $reloads): array
    {
        $pools = $expected = [];
        foreach ($files as $index => $file) {
            $unit = ValidatedData::string(($reloads[$index] ?? [])['unit'] ?? '');
            [$version, $user] = match (true) {
                preg_match('#\A/etc/php/(\d\.\d{1,2})/fpm/pool\.d/orbit-scopes\.conf\z#', ValidatedData::string($file['path']), $match) === 1 => [$match[1], null],
                preg_match('#\Aorbit-([a-z_][a-z0-9_-]*)-php(\d\.\d{1,2})-fpm\.service\z#', $unit, $match) === 1 => [$match[2], $match[1]],
                default => throw new EngineUnsupported('A PHP-FPM pool outside the two pool kinds runs on Swamp.'),
            };
            $expected[] = [$user === null ? "/etc/php/{$version}/fpm/pool.d/orbit-scopes.conf" : "/etc/orbit/php-fpm/{$user}/generated/pool.conf",
                $user === null ? ['/usr/sbin/php-fpm'.$version, '-t'] : ['/usr/bin/env', "PHP_INI_SCAN_DIR=/etc/php/{$version}/fpm/conf.d:/etc/orbit/php-fpm/{$user}/generated",
                    '/usr/sbin/php-fpm'.$version, '-t', '--fpm-config', "/etc/orbit/php-fpm/{$user}/generated/php-fpm.conf"],
                $user === null ? "php{$version}-fpm.service" : "orbit-{$user}-php{$version}-fpm.service"];
            $pools[] = ['version' => $version, 'user' => $user, 'content' => ['blob' => ['content' => ValidatedData::string($file['content'])]]];
        }
        $admitted = array_map(static fn (array $file, mixed $test, mixed $reload): array => [ValidatedData::string($file['path']),
            ValidatedData::stringList(ValidatedData::object($test)['argv']), ValidatedData::string(ValidatedData::object($reload)['unit'])], $files, $tests, $reloads);
        if ($admitted !== $expected) {
            throw new EngineUnsupported('PHP-FPM pool commands outside the derived shapes run on Swamp.');
        }

        return $pools;
    }

    /** The first certificate of a signed reply: the leaf, without the root after it. */
    private static function leafPem(string $signed): string
    {
        $begin = '-----BEGIN CERTIFICATE-----';
        $parts = explode($begin, $signed);
        if (count($parts) < 2) {
            throw new RuntimeException('route.signed_certificate_missing');
        }

        return $begin.$parts[1];
    }

    /**
     * Signs one leaf's request through the same action as `operation:direct:certificate-sign`: it checks the admitted leaf,
     * the root, the run and that no cancel is pending, and records the Activity.
     *
     * @param  array<string, mixed>  $leaf
     */
    private function sign(Operation $operation, array $leaf, string $request, EngineRun $run): string
    {
        $certificates = $this->signer->handle($operation, ValidatedData::integer($operation->node_id), $run->runId(), [[
            'node_id' => ValidatedData::integer($leaf['node_id']), 'scope' => ValidatedData::string($leaf['scope']), 'state' => 'request',
            'request_base64' => base64_encode($request),
        ]]);
        $certificate = base64_decode(ValidatedData::string(($certificates[0] ?? [])['certificate_base64'] ?? ''), true);

        return is_string($certificate) && $certificate !== '' ? $certificate : throw new RuntimeException('certificate.sign_failed');
    }

    /**
     * A TLS handshake with one serving Node by its WireGuard address, the Route's name and the Orbit root alone: the chain and
     * the hostname must check. It needs no application response. Up to three tries a second apart, while Caddy loads the site.
     *
     * @param  array<string, mixed>  $probe
     */
    private function probe(array $probe): string
    {
        $address = ValidatedData::string($probe['address']);
        $domain = ValidatedData::string($probe['domain']);
        $authority = tempnam(sys_get_temp_dir(), 'orbit-probe-ca-');
        if ($authority === false || file_put_contents($authority, $this->root->rootCertificate()) === false) {
            throw new RuntimeException('route.probe_failed');
        }
        try {
            $context = stream_context_create(['ssl' => ['peer_name' => $domain, 'verify_peer' => true, 'verify_peer_name' => true,
                'allow_self_signed' => false, 'cafile' => $authority, 'SNI_enabled' => true]]);
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $socket = @stream_socket_client("ssl://{$address}:443", $code, $message, 5, STREAM_CLIENT_CONNECT, $context);
                if (is_resource($socket)) {
                    fclose($socket);

                    return "verified {$domain} at {$address}";
                }
                if ($attempt < 3) {
                    sleep(1);
                }
            }
        } finally {
            @unlink($authority);
        }

        throw new RuntimeException("route.probe_failed: {$domain} at {$address}");
    }
}
