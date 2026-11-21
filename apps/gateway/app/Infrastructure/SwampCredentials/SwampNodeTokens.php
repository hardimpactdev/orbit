<?php

declare(strict_types=1);

namespace App\Infrastructure\SwampCredentials;

use App\Domain\Nodes\SwampNode;
use App\Domain\SwampCredentials\SwampGatewayToken;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use RuntimeException;

/**
 * Runs the one fixed token entrypoint the swamp role installs on the Swamp Node, over the managed SSH the Gateway already uses to
 * reach that Node, found by role. It adds no grant: the entrypoint mints only the two Gateway bases with their fixed principals,
 * revokes only Gateway generations, and starts the Node's own token renewal. Output that can carry a secret never reaches a message.
 */
class SwampNodeTokens
{
    public const string Entrypoint = '/usr/local/lib/orbit-swamp/gateway-token';

    public function __construct(
        private readonly SshExecutor $ssh,
        private readonly SshKeyProvider $keys,
        private readonly KnownHostsStore $knownHosts,
    ) {}

    /** The reveal JSON of today's `<base>-<YYYYMMDD>[-<n>]` generation, minted or, after a lost reply, the one already minted. */
    public function mint(SwampGatewayToken $kind): string
    {
        return $this->run(['mint', $kind->value], 60.0, 'swamp.gateway_token_mint_failed', secret: true);
    }

    public function revoke(string $name): void
    {
        $this->run(['revoke', $name], 60.0, 'swamp.gateway_token_revoke_failed');
    }

    /** Starts the Swamp Node's own renewal and waits for it to finish. */
    public function renewNode(): void
    {
        $this->run(['renew-node'], 660.0, 'swamp.node_token_renewal_failed');
    }

    /**
     * Every listed access and enrollment token, by name and expiry, and the generation each Swamp Node consumer holds: the name
     * before the first `.` of the serve-driver file and of each worker slot's server.token and enrollment.token.
     *
     * @return array{access: list<array{name:string,principal:string|null,expiresAt:string|null,state:string}>,
     *     enrollment: list<array{name:string,expiresAt:string|null,state:string}>,
     *     held: array{driver:string, workers: list<array{slot:string,access:string,enrollment:string}>}}
     */
    public function list(): array
    {
        $inventory = json_decode($this->run(['list'], 60.0, 'swamp.token_list_failed'), true, 8, JSON_THROW_ON_ERROR);
        $held = is_array($inventory) ? ($inventory['held'] ?? null) : null;
        if (! is_array($inventory) || ! is_array($held) || ! is_string($held['driver'] ?? null) || ! is_array($held['workers'] ?? null)) {
            throw new RuntimeException('swamp.token_list_failed');
        }
        $access = [];
        foreach ($this->rows($inventory['access'] ?? null) as $row) {
            $access[] = ['name' => $row['name'], 'principal' => is_string($row['principal'] ?? null) ? $row['principal'] : null,
                'expiresAt' => $row['expiresAt'], 'state' => $row['state']];
        }
        $workers = [];
        foreach ($held['workers'] as $worker) {
            if (! is_array($worker) || ! is_string($worker['slot'] ?? null) || ! is_string($worker['access'] ?? null) || ! is_string($worker['enrollment'] ?? null)) {
                throw new RuntimeException('swamp.token_list_failed');
            }
            $workers[] = ['slot' => $worker['slot'], 'access' => $worker['access'], 'enrollment' => $worker['enrollment']];
        }

        return ['access' => $access, 'enrollment' => $this->rows($inventory['enrollment'] ?? null),
            'held' => ['driver' => $held['driver'], 'workers' => $workers]];
    }

    /** @return list<array{name:string,expiresAt:string|null,state:string,principal?:mixed}> */
    private function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            throw new RuntimeException('swamp.token_list_failed');
        }
        $checked = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['state'] ?? null) || $row['state'] === ''
                || ! (is_string($row['expiresAt'] ?? null) || ($row['expiresAt'] ?? null) === null)) {
                throw new RuntimeException('swamp.token_list_failed');
            }
            $checked[] = ['name' => $row['name'], 'expiresAt' => $row['expiresAt'] ?? null, 'state' => $row['state'], 'principal' => $row['principal'] ?? null];
        }

        return $checked;
    }

    /** @param list<string> $arguments */
    protected function run(array $arguments, float $timeout, string $code, bool $secret = false): string
    {
        $node = SwampNode::node();
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new RuntimeException($code);
        }
        $result = $this->ssh->execute(new SshConnection(
            host: $node->wireguard_ip,
            user: $node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
            commandTimeout: $timeout,
        ), new RemoteCommand([self::Entrypoint, ...$arguments], timeout: $timeout, maxOutputBytes: 65_536));
        if (self::unconverged($result)) {
            throw new SwampNodeUnconvergedException(SwampNodeUnconvergedException::Code.': '.self::Entrypoint.' is missing on the Swamp Node; converge its swamp role.');
        }
        if (! $result->succeeded()) {
            // stderr names the failure; stdout of a mint may hold a secret and is never quoted.
            throw new RuntimeException($code.($secret ? '' : ': '.substr(trim($result->stderr), -500)));
        }

        return $result->stdout;
    }

    /** The remote shell could not find the entrypoint (exit 127 naming it): the swamp role on the Swamp Node predates the renewal. */
    public static function unconverged(CommandResult $result): bool
    {
        return $result->exitCode === 127 && str_contains($result->stderr, self::Entrypoint);
    }
}
