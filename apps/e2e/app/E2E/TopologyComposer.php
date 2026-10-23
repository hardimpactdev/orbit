<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\ConvergenceReport;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\TopologyTarget;
use Closure;
use RuntimeException;

/**
 * Wires the started guests of one topology into a fleet: bootstraps a fresh test Gateway, then enrolls each
 * declared workload Node and the operator through it. Everything goes through Orbit commands and the
 * harness guest scripts; nothing edits the Gateway database. Enrollment is serial, because every step
 * writes the Gateway's SQLite store.
 */
final readonly class TopologyComposer
{
    private const int STEP_SECONDS = 900;

    public function __construct(
        private IncusHost $host,
        private TopologyGuestPreparer $guests,
        private TopologyNodePrograms $programs,
    ) {}

    /** @param (Closure(string, float): void)|null $progress hears each step and its seconds */
    public function compose(TopologyTarget $target, ?Closure $progress = null): ConvergenceReport
    {
        $instances = $target->instances();
        $addresses = $this->host->globalIpv4All($instances);
        $gateway = $instances['gateway'];
        $gatewayAddress = $target->recipe->node('gateway')->wireGuardAddress();
        $steps = [];

        StageTimer::run($progress, 'bootstrap.gateway', function () use ($target, $gateway, $addresses): void {
            $this->guests->placeGatewayEnvironment($target);
            $this->guests->exposeOrbitCli($target);
            $this->run('bootstrap.gateway', $gateway, 'converge-gateway.sh', ['prerequisites']);
            $rendered = $this->programs->for($target->recipe->node('gateway'), $this->architectures(['gateway' => $gateway])['gateway']);
            // The programs call the pinned agent, so it goes in first (ADR 0225).
            $agent = $this->host->exec($gateway, new GuestCommand(['install', '-D', '-m', '0755', '-o', 'root', '-g', 'root', '--', $rendered['agent']['path'], '/usr/local/bin/orbit-agent'], 60));
            if (! $agent->successful()) {
                throw new RuntimeException('The pinned agent could not be installed on the Gateway: '.trim($agent->stderr));
            }
            foreach ($rendered['programs'] as $program) {
                $result = $this->host->exec($gateway, GuestCommand::asOrbitUser($program['arguments'], self::STEP_SECONDS, $program['input']));
                if (! $result->successful()) {
                    throw new RuntimeException('Gateway convergence program ['.$program['name'].'] failed.');
                }
            }
            $this->run('bootstrap.gateway', $gateway, 'converge-gateway.sh', ['bootstrap', $addresses['gateway']]);
        });
        $steps['bootstrap.gateway'] = true;

        $enrolled = [...$target->recipe->workloadNodes(), $target->recipe->node('operator')];
        $key = $this->gatewayPublicKey($gateway);
        $authorize = [];
        foreach ($enrolled as $node) {
            $authorize[$node->key] = [
                'instance' => $instances[$node->key],
                'command' => new GuestCommand(['/usr/local/bin/prepare-node.sh', 'gateway-authorize', $key], 60),
            ];
        }
        $this->assertBatch('authorize.gateway-ssh', $authorize);
        $enrolledInstances = [];
        foreach ($enrolled as $node) {
            $enrolledInstances[$node->key] = $instances[$node->key];
        }
        $architectures = $this->architectures($enrolledInstances);

        foreach ($target->recipe->workloadNodes() as $node) {
            $name = 'enroll.'.$node->key;
            StageTimer::run($progress, $name, fn () => $this->run($name, $gateway, 'converge-node.sh', [
                'provision',
                $node->key,
                $addresses[$node->key],
                $architectures[$node->key],
                $node->wireGuardAddress(),
                $node->roles[0],
                (string) $node->tld,
            ]));
            $steps[$name] = true;
        }

        $operator = $target->recipe->node('operator');
        StageTimer::run($progress, 'enroll.operator', function () use ($gateway, $gatewayAddress, $instances, $addresses, $architectures, $operator): void {
            $this->run('enroll.operator', $gateway, 'converge-node.sh', [
                'provision',
                $operator->key,
                $addresses['operator'],
                $architectures['operator'],
                $operator->wireGuardAddress(),
            ]);
            $this->run('enroll.operator', $gateway, 'converge-operator.sh', ['grant', 'operator', 'gateway', $gatewayAddress]);
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
            if (! $result->successful() || preg_match('/\A(?:x86_64|aarch64)\z/', $architecture) !== 1) {
                throw new RuntimeException("Node {$node} reported no valid machine architecture.");
            }
            $architectures[$node] = $architecture;
        }

        return $architectures;
    }

    private function provisioningSuffix(string $script, GuestCommandResult $result): string
    {
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
