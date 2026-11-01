<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ConvergenceReport;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\TopologyTarget;
use Closure;
use RuntimeException;

/** Composes the disposable fixture fleet, then uses normal Gateway admission and native execution. */
final readonly class TopologyComposer
{
    private const int STEP_SECONDS = 900;

    public function __construct(
        private IncusHost $host,
        private TopologyGuestPreparer $guests,
        private TopologyNodeRecipe $recipes,
        private TopologySwamp $swamp,
        private TopologyFixtureEnrollment $fixtures,
        private TopologyFixtureNetwork $network,
        private TopologyGatewayRuntime $runtime,
    ) {}

    /** @param (Closure(string, float): void)|null $progress hears each step and its seconds */
    public function compose(TopologyTarget $target, string $worktree, string $commit, ?Closure $progress = null): ConvergenceReport
    {
        $instances = $target->instances();
        $addresses = $this->host->globalIpv4All($instances);
        $gateway = $instances['gateway'];
        $gatewayAddress = $target->recipe->node('gateway')->wireGuardAddress();
        $steps = [];

        StageTimer::run($progress, 'prepare.swamp', fn () => $this->swamp->prepare($target));
        $steps['prepare.swamp'] = true;

        StageTimer::run($progress, 'prepare.gateway', function () use ($target, $gateway): void {
            $this->guests->placeGatewayEnvironment($target);
            $this->guests->exposeOrbitCli($target);
            $this->run('prepare.gateway', $gateway, 'converge-gateway.sh', ['prerequisites']);
            $rendered = $this->recipes->for($target->recipe->node('gateway'), $this->architectures(['gateway' => $gateway])['gateway']);
            // Deliver the frozen observer; native product workflows perform the effects.
            $agent = $this->host->exec($gateway, new GuestCommand(['install', '-D', '-m', '0755', '-o', 'root', '-g', 'root', '--', $rendered['agent']['path'], '/usr/local/bin/orbit-agent'], 60));
            if (! $agent->successful()) {
                throw new RuntimeException('The pinned agent could not be installed on the Gateway: '.trim($agent->stderr));
            }
            $this->run('migrate.gateway', $gateway, 'converge-gateway.sh', ['migrate']);
            $this->run('fixture.ca', $gateway, 'converge-gateway.sh', ['fixture-ca']);
        });
        $steps['prepare.gateway'] = true;

        $architectures = $this->architectures($instances);
        $repository = StageTimer::run($progress, 'fixture.repository', function () use ($target, $gateway): array {
            $result = $this->host->exec($gateway, new GuestCommand(
                ['sudo', '-n', 'python3', '-I', '/home/orbit/orbit/tools/swamp_proof_repository.py', 'seed', $target->issue, 'repositories.orbit'], 180,
            ));
            $value = json_decode($result->stdout, true);
            if (! $result->successful() || ! is_array($value) || array_keys($value) !== ['url', 'commit', 'branch']
                || ! is_string($value['url']) || ! is_string($value['commit']) || ! is_string($value['branch'])) {
                throw new RuntimeException('e2e.fixture_seed_repository_failed.');
            }

            return $value;
        });
        $input = StageTimer::run($progress, 'fixture.identities', fn () => $this->fixtures->capture($target, $addresses, $architectures, $repository));
        StageTimer::run($progress, 'seed.gateway', fn (): array => $this->fixtures->seed($target, $input));
        $this->run('fixture.gateway-leaf', $gateway, 'converge-gateway.sh', ['fixture-gateway-leaf']);
        $steps['fixture.enrollment'] = true;
        StageTimer::run($progress, 'runtime.gateway', fn () => $this->runtime->prepare($target, $worktree, $commit));
        $steps['runtime.gateway'] = true;
        StageTimer::run($progress, 'configure.gateway', fn () => $this->run('configure.gateway', $gateway, 'converge-gateway.sh', ['configure']));
        $steps['configure.gateway'] = true;

        StageTimer::run($progress, 'fixture.network', fn () => $this->network->converge($target, $input['nodes']));
        StageTimer::run($progress, 'fixture.trust', function () use ($target, $gateway): void {
            $result = $this->host->exec($gateway, new GuestCommand(['cat', '/home/orbit/.orbit/ca/root.pem'], 10));
            if (! $result->successful() || openssl_x509_read($result->stdout) === false) {
                throw new RuntimeException('e2e.fixture_ca_invalid.');
            }
            $commands = [];
            $key = $this->gatewayPublicKey($gateway);
            foreach ($target->instances() as $name => $instance) {
                $commands['trust.'.$name] = ['instance' => $instance, 'command' => new GuestCommand(['/usr/local/bin/prepare-node.sh', 'fixture-trust'], 60, $result->stdout)];
                $commands['authorize.'.$name] = ['instance' => $instance, 'command' => new GuestCommand(['/usr/local/bin/prepare-node.sh', 'gateway-authorize', $key], 60)];
            }
            $this->assertBatch('fixture.trust', $commands);
        });
        StageTimer::run($progress, 'fixture.repositories', function () use ($gateway, $addresses): void {
            $result = $this->host->exec($gateway, new GuestCommand(['/usr/local/bin/topology-repositories.py', $addresses['gateway']], 660));
            if (! $result->successful() || json_decode($result->stdout, true) !== ['fixture_repositories' => 'separate-caddy', 'site' => 'repositories.orbit', 'fcgiwrap' => '1.1.0-16build3']) {
                throw new RuntimeException('e2e.fixture_repository_service_failed.');
            }
        });
        StageTimer::run($progress, 'start.swamp', fn () => $this->swamp->start($target));
        $steps['start.swamp'] = true;
        StageTimer::run($progress, 'prepare-release.gateway', fn () => $this->run('prepare-release.gateway', $gateway, 'converge-gateway.sh', ['prepare-release', $commit]));
        StageTimer::run($progress, 'deploy-release.gateway', fn () => $this->run('deploy-release.gateway', $gateway, 'converge-gateway.sh', ['deploy-release', $commit]));
        StageTimer::run($progress, 'services.gateway', fn () => $this->run('services.gateway', $gateway, 'converge-gateway.sh', ['services']));
        $steps['deploy-release.gateway'] = true;

        StageTimer::run($progress, 'enroll.operator', function () use ($gatewayAddress, $instances): void {
            $this->run('enroll.operator', $instances['operator'], 'converge-operator.sh', ['configure-cli', $gatewayAddress]);
        });
        $steps['enroll.operator'] = true;

        // Declared extra roles go through the product path, from the operator's CLI, after every Node exists.
        foreach ($target->recipe->workloadNodes() as $node) {
            $roles = $target->recipe->extraRoles($node);
            if ($roles === []) {
                continue;
            }
            $name = 'roles.'.$node->key;
            $ownCluster = $target->recipe->ownClusterRoles($node);
            StageTimer::run($progress, $name, function () use ($name, $instances, $node, $roles, $ownCluster): void {
                foreach ($roles as $role) {
                    $this->run($name, $instances['operator'], 'converge-operator.sh', [
                        'role-add',
                        $node->key,
                        $role,
                        ...(in_array($role, $ownCluster, true) ? ['e2e-'.$node->key] : []),
                    ]);
                }
            });
            $steps[$name] = true;
        }

        return ConvergenceReport::successful($steps);
    }

    /** @param list<string> $arguments */
    private function run(string $step, string $instance, string $script, array $arguments): GuestCommandResult
    {
        $result = $this->host->exec(
            $instance,
            new GuestCommand(['/usr/local/bin/'.$script, ...$arguments], self::STEP_SECONDS),
        );
        if (! $result->successful()) {
            throw new RuntimeException((new GuestFailureDetail)->append(
                "Step {$step} failed: guest script {$script} exited {$result->exitCode} on {$instance}"
                .$this->provisioningSuffix($script, $result).'.',
                $result->stderr,
            ));
        }

        return $result;
    }

    /** @param array<string, array{instance:string,command:GuestCommand}> $commands */
    private function assertBatch(string $step, array $commands): void
    {
        $failed = [];
        foreach ($this->host->execAll($commands) as $label => $result) {
            if (! $result->successful()) {
                $failed[] = (new GuestFailureDetail)->append("{$label} exited {$result->exitCode}", $result->stderr);
            }
        }
        if ($failed !== []) {
            throw new RuntimeException("Step {$step} failed: ".implode('; ', $failed));
        }
    }

    private function gatewayPublicKey(string $gateway): string
    {
        $result = $this->host->exec($gateway, new GuestCommand(['ssh-keygen', '-y', '-f', '/home/orbit/.orbit/ssh/id_ed25519'], 10));
        $key = trim($result->stdout);
        if (! $result->successful() || preg_match('/\A(ssh-ed25519 [A-Za-z0-9+\/]+={0,2})(?: [^\r\n]+)?\z/D', $key, $matches) !== 1) {
            throw new RuntimeException('Step authorize.gateway-ssh failed: the Gateway SSH public key is missing or invalid.');
        }

        return $matches[1];
    }

    /**
     * @param  array<string, string>  $instances
     * @return array<string, string>
     */
    private function architectures(array $instances): array
    {
        $requests = [];
        foreach ($instances as $node => $instance) {
            $requests[$node] = ['instance' => $instance, 'command' => new GuestCommand(['uname', '-m'], 10)];
        }
        $architectures = [];
        foreach ($this->host->execAll($requests) as $node => $result) {
            $architecture = trim($result->stdout);
            if (! $result->successful() || $architecture !== 'x86_64') {
                throw new RuntimeException("Node {$node} reported no valid machine architecture.");
            }
            $architectures[$node] = $architecture;
        }

        return $architectures;
    }

    private function provisioningSuffix(string $script, GuestCommandResult $result): string
    {
        if ($script === 'converge-gateway.sh') {
            $failure = json_decode($result->stdout, true);
            if (is_array($failure) && is_string($failure['error_code'] ?? null) && is_string($failure['step'] ?? null)
                && preg_match('/\Agateway\.[a-z0-9._-]{1,96}\z/D', $failure['error_code']) === 1
                && preg_match('/\A[a-z0-9:-]{1,64}\z/D', $failure['step']) === 1) {
                return " at Orbit step {$failure['step']} ({$failure['error_code']})";
            }
        }
        $pattern = match (true) {
            $script === 'converge-gateway.sh' && $result->exitCode === 71 => '/(?:\A|\R)Gateway bootstrap failed at step \[([a-z0-9:-]+)\] with error \[([a-z0-9._-]+)\]\.(?:\R|\z)/D',
            $script === 'converge-node.sh' => '/(?:\A|\R)Node provisioning failed at step \[([a-z0-9:-]+)\] with error \[([a-z0-9._-]+)\]\.(?:\R|\z)/D',
            default => null,
        };

        if ($pattern === null || preg_match($pattern, $result->stdout."\n".$result->stderr, $failure) !== 1) {
            return '';
        }

        return " at Orbit step {$failure[1]} ({$failure[2]})";
    }
}
