<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentBinaryInput;
use App\Infrastructure\AgentCommands\AgentBinaryOutput;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Node;
use LogicException;

/**
 * For Route tests whose SSH fakes model TLS checks and development leaves. A leaf command keeps the
 * shape those fakes read: its scope at argument 3, and a marker input for a reuse, a presence check, or a removal.
 * Every other agent command goes to `$others`.
 */
final readonly class SshCommandAgents implements AdmissionAgentRunner, AgentCommandRunner
{
    public function __construct(private SshExecutor $ssh, private ?AgentCommandRunner $others = null) {}

    public function run(Node $node, string $command, array $input = [], ?float $deadlineSeconds = null, ?AgentBinaryOutput $output = null, ?AgentBinaryInput $binaryInput = null): array
    {
        return $this->runOver($node, new SshConnection((string) $node->wireguard_ip, $node->user, 22, '/tmp/orbit-test-key', '/tmp/orbit-test-known-hosts', commandTimeout: $deadlineSeconds ?? 900.0), $command, $input, $deadlineSeconds);
    }

    public function architectureOver(Node $node, SshConnection $connection): string
    {
        throw new LogicException('No candidate staging in this fixture.');
    }

    public function installOver(Node $node, SshConnection $connection): bool
    {
        return false;
    }

    public function runOver(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        [$arguments, $stdin] = match ($command) {
            'routes.verify-certificate' => [['timeout', '10', 'openssl', 's_client', '-connect', "{$input['address']}:{$input['port']}", '-servername', $input['domain'], '-verify_return_error'], ''],
            'certificates.request-leaf' => [['bash', '-seu', '--', $input['scope'], $input['domain'], $input['version'], $input['root_hash'], $input['user'], '', $input['home']], "printf 'CURRENT\\n'"],
            'certificates.publish-leaf' => [['bash', '-ceu', 'orbit-certificates/$scope', '--', $input['scope'], $input['domain'], $input['version'], $input['root_hash']], $input['certificate'].$input['root_certificate']],
            'certificates.inspect-leaf' => [['bash', '-seu', '--', $input['scope'], $input['home']], "printf 'PRESENT\\n'"],
            'certificates.remove-leaf' => [['bash', '-seu', '--', $input['scope'], '', '', $input['home']], 'rm -rf -- "$managed_home/.orbit/certificates/$scope"'."\n".'sudo rm -rf -- "/etc/caddy/orbit-certificates/$scope"'],
            default => [null, null],
        };
        if ($arguments === null) {
            return ($this->others ?? throw new LogicException("No answer for agent command [{$command}]."))->run($node, $command, $input, $deadlineSeconds);
        }
        $result = $this->ssh->execute($connection, new RemoteCommand($arguments, input: $stdin));
        if (! $result->succeeded()) {
            [$code, $details] = match (true) {
                $command === 'routes.verify-certificate' => ['routes.certificate_invalid', ['stderr' => $result->stderr]],
                default => ['certificates.leaf_failed', ['step' => $command]],
            };
            throw new AgentCommandRefused($command, $code, 'Failed.', $details);
        }

        return match ($command) {
            'certificates.request-leaf' => trim($result->stdout) === 'CURRENT' ? ['state' => 'current'] : ['state' => 'request', 'request' => $result->stdout],
            'certificates.inspect-leaf' => ['present' => trim($result->stdout) === 'PRESENT'],
            default => [],
        };
    }

    public function install(Node $node): bool
    {
        return false;
    }
}
