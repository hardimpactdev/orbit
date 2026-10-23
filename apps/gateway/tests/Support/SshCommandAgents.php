<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Firewall\UfwStoredRuleProbe;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use LogicException;

/**
 * For route and firewall tests whose SSH fakes model a Node's tools: each UFW and certificate agent command reaches
 * the fake as the command it runs on a Node, such as `sudo ufw status numbered`. Every other agent command goes to
 * `$others`.
 */
final readonly class SshCommandAgents implements AgentCommandRunner
{
    public function __construct(private SshExecutor $ssh, private ?AgentCommandRunner $others = null) {}

    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        [$arguments, $stdin] = match ($command) {
            'firewall.status' => [['sudo', 'ufw', 'status', 'numbered'], null],
            'firewall.ufw' => [['sudo', 'ufw', ...$input['arguments']], null],
            'routes.verify-certificate' => [['timeout', '10', 'openssl', 's_client', '-connect', "{$input['address']}:{$input['port']}", '-servername', $input['domain'], '-verify_return_error'], ''],
            default => [null, null],
        };
        if ($arguments === null) {
            return ($this->others ?? throw new LogicException("No answer for agent command [{$command}]."))->run($node, $command, $input, $deadlineSeconds);
        }
        $connection = new SshConnection((string) $node->wireguard_ip, $node->user, 22, '/tmp/orbit-test-key', '/tmp/orbit-test-known-hosts', commandTimeout: $deadlineSeconds ?? 900.0);
        $result = $this->ssh->execute($connection, new RemoteCommand($arguments, input: $stdin));
        if (! $result->succeeded()) {
            $code = $command === 'routes.verify-certificate' ? 'routes.certificate_invalid' : 'firewall.ufw_failed';
            throw new AgentCommandRefused($command, $code, 'Failed.', $command === 'routes.verify-certificate'
                ? ['stderr' => $result->stderr]
                : ['exit_code' => $result->exitCode, 'stdout' => $result->stdout, 'stderr' => $result->stderr]);
        }
        if ($command === 'firewall.status') {
            $stored = ($input['stored'] ?? false) === true
                ? $this->ssh->execute($connection, new RemoteCommand(UfwStoredRuleProbe::arguments()))->stdout
                : '';

            return ['status' => $result->stdout, 'stored' => array_values(array_filter(explode("\n", $stored)))];
        }

        return $command === 'firewall.ufw' ? ['stdout' => $result->stdout] : [];
    }

    public function install(Node $node): bool
    {
        return false;
    }
}
