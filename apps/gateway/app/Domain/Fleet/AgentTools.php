<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Container\Attributes\Tag;

/**
 * The daily update of the agent CLIs on the T3 hosts and of the model router (ADR 0255,
 * [Agent tool updates](/reference/gateway-recovery#agent-tool-updates)). Each host keeps its own install method: Swamp updates what
 * is installed, the way it is installed, and records each tool's version before and after. Containers are left out: they run
 * the pinned shared agent folder.
 */
final readonly class AgentTools
{
    public const string Kind = 'fleet.agent_tools';

    /** Daily, before the 03:00 fleet window, so a reboot pass never cuts an install. */
    public const array Schedule = ['timezone' => 'Europe/Amsterdam', 'at' => '02:41'];

    /** @param iterable<AgentToolsContribution> $contributions */
    public function __construct(
        #[Tag(AgentToolsContribution::class)] private iterable $contributions,
        private ManagedNodeEligibility $eligibility,
        private SwampSshExecutionInput $ssh,
    ) {}

    /** The run that is open now, if any. */
    public static function open(): ?Operation
    {
        return Operation::query()->where('kind', self::Kind)->whereNotIn('status', OperationStatus::terminalValues())->latest('id')->first();
    }

    /**
     * The managed Linux hosts whose T3 server runs the agent CLIs, by Node id. macOS Nodes stay out: the operator Macs get no
     * automatic CLI updates (decided 2026-11-07). Task VMs stay out: they are sandboxes.
     *
     * @return list<array{node_id: int, name: string, host: string, user: string, port: int, host_keys: list<string>, identity_file: string}>
     */
    public function hosts(): array
    {
        $nodes = [];
        foreach ($this->contributions as $contribution) {
            foreach ($contribution->hosts() as $node) {
                $nodes[$node->id] = $node;
            }
        }
        ksort($nodes);
        $hosts = [];
        foreach ($nodes as $node) {
            if (! $this->eligibility->allows($node) || TaskVm::forNode($node) instanceof TaskVm) {
                continue;
            }
            $ssh = $this->ssh->forNode($node);
            $keys = $ssh['target']['host_keys'];
            if ($keys === null) {
                continue;
            }
            $hosts[] = ['node_id' => $node->id, 'name' => $node->name, 'host' => $ssh['target']['host'], 'user' => $ssh['target']['user'],
                'port' => $ssh['target']['port'], 'host_keys' => $keys, 'identity_file' => $ssh['identity_file']];
        }

        return $hosts;
    }

    /** @return array<string, mixed>|null The router a contribution names, or null when none does. */
    public function router(): ?array
    {
        foreach ($this->contributions as $contribution) {
            $router = $contribution->router();
            if ($router !== null) {
                return $router;
            }
        }

        return null;
    }
}
