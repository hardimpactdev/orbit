<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class NodeAgentSshExecutor implements NodeAgentRuntime
{
    /**
     * How long one converge may hold the Node's agent lock. A converge that crashes without releasing
     * it, such as a PHP-FPM worker killed at `request_terminate_timeout`, blocks the Node's next agent
     * converge for at most this long. It covers the wait for the Node's update lock, which a self-update
     * may hold while the native installer waits up to 300 seconds.
     */
    private const int LockSeconds = 420;

    public function __construct(
        private LeafCertificateSigner $certificates,
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private AgentCommandRunner $agents,
        private ?NodeLocks $locks = null,
        /** How long a converge waits for another converge of the same Node agent. */
        private int $lockWaitSeconds = 120,
    ) {}

    /**
     * The Node's Instance root, or null when the managed user or a safe path cannot be resolved.
     */
    private function instanceRoot(Node $node): ?string
    {
        try {
            $account = $this->accounts->resolve($node);
            $root = $this->storageRoots->resolveApps($this->nodeSettings->fromStored($node->settings), $account)->value;
        } catch (Throwable) {
            return null;
        }

        if (preg_match('#\A(/[A-Za-z0-9._-]+)+\z#D', $root) !== 1 || str_contains($root, '/..') || str_contains($root, '/./')) {
            return null;
        }

        return $root;
    }

    /**
     * The unit lines that let the agent read the Node's task checkouts and nothing else under `/home` or
     * `/root` (ADR 0151). `/home` and `/root` become empty, and only the Instance root is bound back
     * read-only. Root without capabilities reads there only what other users may read. Without a
     * resolvable root, the agent sees no home directory at all.
     *
     * @return list<string>
     */
    private function checkoutAccess(?string $root): array
    {
        if ($root === null) {
            return ['ProtectHome=yes'];
        }

        $underHome = str_starts_with($root.'/', '/home/') || str_starts_with($root.'/', '/root/');

        return ['ProtectHome=tmpfs', ...($underHome ? ['BindReadOnlyPaths=-'.$root] : [])];
    }

    /**
     * Every Instance checkout in the Instance root. The agent removes the world bits from their `.env`,
     * so it, like every other local user, cannot read them.
     *
     * @return list<string>
     */
    private function checkouts(Node $node, ?string $root): array
    {
        if ($root === null || ! $node->exists) {
            return [];
        }

        return array_values(Instance::query()
            ->where('node_id', $node->getKey())
            ->orderBy('id')
            ->with(['project', 'node.roles'])
            ->get()
            ->map(static fn (Instance $instance): string => $instance->applicationDirectory())
            ->filter(static fn (string $path): bool => str_starts_with($path, $root.'/') && ! str_contains($path, '/..'))
            ->all());
    }

    /** @return array{configuration: string, certificate: string, unit: string, checkouts: list<string>} */
    public function configuration(Node $node): array
    {
        $this->guardLinuxPlatform($node);
        $gatewayAddress = $this->gatewayAddress();

        if (! is_string($gatewayAddress) || filter_var($gatewayAddress, FILTER_VALIDATE_IP) === false) {
            throw new ResourceOperationException('agent.install_failed', 'The active Gateway has no managed WireGuard address.', 409);
        }

        $root = $this->instanceRoot($node);
        $configuration = "gateway_url = \"https://gateway.orbit\"\ngateway_address = \"{$gatewayAddress}\"\n";
        if (app(NodeWorkerRollout::class)->allows($node)) {
            $configuration .= 'swamp_worker = true'."\n";
        }
        $certificate = $this->certificates->rootCertificate();
        $unit = implode("\n", [
            NodeAgentFootprint::Marker,
            '[Unit]',
            'Description=Orbit Node agent',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'ExecStart='.NodeAgentFootprint::BinaryPath,
            'Restart=always',
            'RestartSec=2',
            'User=root',
            'Group=root',
            'CapabilityBoundingSet=',
            'NoNewPrivileges=yes',
            'ProtectSystem=strict',
            ...$this->checkoutAccess($root),
            'PrivateTmp=yes',
            'MemoryMax=128M',
            'RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);

        return ['configuration' => $configuration, 'certificate' => $certificate, 'unit' => $unit,
            'checkouts' => $this->checkouts($node, $root)];
    }

    public function converge(Node $node): void
    {
        $input = $this->configuration($node);
        $configuration = $input['configuration'];
        $certificate = $input['certificate'];
        $unit = $input['unit'];
        $root = $this->instanceRoot($node);

        // Only one converge per Node runs at a time, so two converges cannot write different secrets
        // and store the other one's hash.
        $lock = ($this->locks ?? app(NodeLocks::class))->lock('node-agent:'.($node->exists ? 'id:'.StoredValue::integer($node->getKey()) : 'name:'.$node->name), self::LockSeconds);

        try {
            $lock->block($this->lockWaitSeconds);
        } catch (LockTimeoutException $exception) {
            throw new ResourceOperationException('agent.converge_busy', 'Another converge of this Node agent is still running.', 409, $exception);
        }

        try {
            $this->convergeLocked($lock, $node, $root, $configuration, $certificate, $unit);
        } finally {
            $lock->release();
        }
    }

    private function guardLinuxPlatform(Node $node): void
    {
        if ($node->platform === 'linux') {
            return;
        }

        throw new ResourceOperationException(
            'node.agent_unsupported',
            'The Node agent supports Linux nodes only.',
            422,
        );
    }

    /**
     * Installs the agent, then has it write its files and switches the Gateway's secret record (ADR 0155).
     * `agent.configure` keeps the secret while it matches the record and otherwise writes a new one, and
     * answers only its hash once the agent restarted from it. Every earlier failure leaves the record
     * unchanged, so the running agent keeps its accepted secret and Doctor reports `mismatch`; the next
     * converge writes a new secret and restarts the agent. The agent holds the update lock that
     * `orbit self-update` holds, so a self-update never swaps or restarts the agent meanwhile.
     *
     * Before each step that changes the Node or the record, the converge renews its lock and stops
     * when the lock has expired, whether or not another converge has taken it since.
     */
    private function convergeLocked(NodeLock $lock, Node $node, ?string $root, string $configuration, string $certificate, string $unit): void
    {
        $this->holdLock($lock);

        try {
            $binaryChanged = $this->agents->install($node);
            $this->holdLock($lock);
            $stored = $node->exists ? Node::query()->whereKey($node->getKey())->value('agent_secret_hash') : $node->agent_secret_hash;
            $configured = $this->agents->run($node, 'agent.configure', [
                'secret_hash' => is_string($stored) && preg_match('/\A[0-9a-f]{64}\z/D', $stored) === 1 ? $stored : null,
                'configuration' => $configuration,
                'certificate' => $certificate,
                'unit' => $unit,
                'checkouts' => $this->checkouts($node, $root),
                'binary_changed' => $binaryChanged,
            ]);
        } catch (AgentCommandRefused $exception) {
            $cause = $exception->details['cause'] ?? $exception->details['step'] ?? $exception->errorCode;
            throw new ResourceOperationException('agent.install_failed', $exception->getMessage().' Cause: '.(is_string($cause) ? $cause : $exception->errorCode).'.', 502, $exception);
        } catch (AgentRunnerFailure $exception) {
            throw new ResourceOperationException('agent.install_failed', $exception->getMessage().' Cause: '.$exception->kind.'.', 502, $exception);
        }

        if (($configured['unclosed'] ?? []) !== []) {
            Log::warning('The agent converge could not close Instance environments.', ['node_id' => $node->getKey(), 'checkouts' => $configured['unclosed']]);
        }

        $this->holdLock($lock);
        $this->recordSecret($node, StoredValue::string($configured['secret_hash'] ?? null));
    }

    /**
     * Renews the Node's agent lock for another full term, or stops the converge once the lock has expired,
     * because another converge may hold it, so two converges never write the secret or its record together.
     */
    private function holdLock(NodeLock $lock): void
    {
        if (! $lock->refresh()) {
            throw new ResourceOperationException('agent.converge_lock_lost', 'The Node agent converge lock expired before it could be renewed.', 409);
        }
    }

    private function recordSecret(Node $node, string $hash): void
    {
        $attributes = ['agent_secret_hash' => $hash];
        $node->forceFill($attributes)->syncOriginalAttributes(array_keys($attributes));

        if ($node->exists) {
            Node::query()->whereKey($node->getKey())->firstOrFail()->forceFill($attributes)->save();
        }
    }

    public function remove(Node $node): void
    {
        if ($node->platform !== 'linux') {
            return;
        }

        try {
            $this->agents->run($node, 'agent.remove');
        } catch (Throwable $exception) {
            throw new ResourceOperationException('agent.remove_failed', 'The Node agent could not be removed.', 502, $exception);
        }
    }

    /**
     * The Gateway's WireGuard address. A `gateway` role converge marks its assignment provisioning
     * while it runs, and it converges the agent too, so a converging holder counts; an active one wins.
     */
    private function gatewayAddress(): mixed
    {
        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $status) {
            $address = Node::query()
                ->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($query) => $query
                    ->where('role', RoleName::Gateway)
                    ->where('status', $status))
                ->value('wireguard_ip');

            if ($address !== null) {
                return $address;
            }
        }

        return null;
    }
}
