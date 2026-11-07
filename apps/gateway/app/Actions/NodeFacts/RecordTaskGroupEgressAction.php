<?php

declare(strict_types=1);

namespace App\Actions\NodeFacts;

use App\Domain\NodeFacts\NodeFactKind;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\TaskGroupEgress;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Adds the rows of an `egress` fact to the task group that holds the VM it names (docs/reference/task-vm-egress-log.md).
 * A VM no group holds, such as a base VM, adds nothing. A row is added once for each destination, port and protocol;
 * a later report adds its addresses, sources and flags and never removes anything, so the group keeps its list after its
 * VM and Node are deleted. A row that the agent first saw before the group claimed the VM is not the group's and is left out.
 * An unchanged report reads the rows it names and writes nothing, so it takes no write lock.
 */
final readonly class RecordTaskGroupEgressAction
{
    /** The most rows one group keeps, so a runaway VM cannot grow the table without limit. */
    public const int MaxRows = 20000;

    /** The most addresses one row keeps. */
    private const int MaxAddresses = 32;

    /**
     * @param  array<string, mixed>  $data  validated by {@see NodeFactKind::rules()}
     * @return int the number of rows written
     */
    public function execute(Node $host, string $vmName, array $data): int
    {
        $vm = TaskVm::query()
            ->where('host_node_id', $host->id)
            ->where('name', $vmName)
            ->whereNotNull('group_id')
            ->where('state', '!=', TaskVmState::Destroyed->value)
            ->latest('id')
            ->first();
        if (! $vm instanceof TaskVm || $vm->group_id === null) {
            return 0;
        }
        $groupId = (int) $vm->group_id;
        $reported = $this->reported($data, $vm->claimed_at === null ? null : CarbonImmutable::instance($vm->claimed_at));
        if (ValidatedData::integer($data['overflow'] ?? 0) > 0) {
            Log::warning('A task VM reported more egress rows than the agent keeps.', ['group' => $groupId, 'vm' => $vmName, 'overflow' => $data['overflow']]);
        }
        $room = max(0, self::MaxRows - TaskGroupEgress::query()->where('task_id', $groupId)->count());
        if ($room === 0) {
            Log::warning('A task group is at its egress row limit.', ['group' => $groupId, 'limit' => self::MaxRows]);
        }
        if ($reported === [] || $this->changes($groupId, $reported, $room) === []) {
            return 0;
        }

        // A change takes the write lock once, and is worked out again inside it.
        return DB::transaction(function () use ($groupId, $vm, $reported): int {
            $room = max(0, self::MaxRows - TaskGroupEgress::query()->where('task_id', $groupId)->count());
            $written = 0;
            foreach ($this->changes($groupId, $reported, $room) as [$existing, $row]) {
                if ($existing instanceof TaskGroupEgress) {
                    $existing->update(['addresses' => $row['addresses'], 'sources' => $row['sources'], 'flags' => $row['flags']]);
                } else {
                    TaskGroupEgress::query()->create(['task_id' => $groupId, 'vm_name' => $vm->name, ...$row]);
                }
                $written++;
            }

            return $written;
        });
    }

    /**
     * The rows of the report by key, with the lists sorted and unique. Two rows with one key merge.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{destination: string, kind: string, port: int, protocol: string, addresses: list<string>, sources: list<string>, flags: list<string>, first_seen_at: CarbonImmutable}>
     */
    private function reported(array $data, ?CarbonImmutable $claimed): array
    {
        $reported = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            $row = ValidatedData::object($row);
            $firstSeen = CarbonImmutable::parse(ValidatedData::string($row['first_seen']));
            if ($claimed instanceof CarbonImmutable && $firstSeen->lt($claimed)) {
                continue;
            }
            $port = is_int($row['port'] ?? null) ? $row['port'] : 0;
            $protocol = is_string($row['protocol'] ?? null) ? $row['protocol'] : '';
            $destination = ValidatedData::string($row['destination']);
            $key = $destination."\0".$port."\0".$protocol;
            $known = $reported[$key] ?? null;
            $reported[$key] = [
                'destination' => $destination,
                'kind' => ValidatedData::string($row['kind']),
                'port' => $port,
                'protocol' => $protocol,
                'addresses' => self::union($known['addresses'] ?? [], ValidatedData::stringList($row['addresses'] ?? [])),
                'sources' => self::union($known['sources'] ?? [], ValidatedData::stringList($row['sources'] ?? [])),
                'flags' => self::union($known['flags'] ?? [], ValidatedData::stringList($row['flags'] ?? [])),
                'first_seen_at' => $known === null || $firstSeen->lt($known['first_seen_at']) ? $firstSeen : $known['first_seen_at'],
            ];
        }

        return $reported;
    }

    /**
     * What the report adds or changes: a new row while `$room` allows, and a known row whose lists grow.
     *
     * @param  array<string, array{destination: string, kind: string, port: int, protocol: string, addresses: list<string>, sources: list<string>, flags: list<string>, first_seen_at: CarbonImmutable}>  $reported
     * @return list<array{0: TaskGroupEgress|null, 1: array{destination: string, kind: string, port: int, protocol: string, addresses: list<string>, sources: list<string>, flags: list<string>, first_seen_at: CarbonImmutable}}>
     */
    private function changes(int $groupId, array $reported, int $room): array
    {
        $stored = TaskGroupEgress::query()->where('task_id', $groupId)
            ->whereIn('destination', array_values(array_unique(array_column($reported, 'destination'))))->get()
            ->keyBy(static fn (TaskGroupEgress $row): string => $row->destination."\0".$row->port."\0".$row->protocol);
        $changes = [];
        foreach ($reported as $key => $row) {
            $existing = $stored->get($key);
            if (! $existing instanceof TaskGroupEgress) {
                if ($room-- > 0) {
                    $changes[] = [null, [...$row, 'addresses' => array_slice($row['addresses'], 0, self::MaxAddresses)]];
                }

                continue;
            }
            $merged = [
                ...$row,
                'addresses' => array_slice(self::union($existing->addresses, $row['addresses']), 0, self::MaxAddresses),
                'sources' => self::union($existing->sources, $row['sources']),
                'flags' => self::union($existing->flags, $row['flags']),
            ];
            if ($merged['addresses'] !== $existing->addresses || $merged['sources'] !== $existing->sources || $merged['flags'] !== $existing->flags) {
                $changes[] = [$existing, $merged];
            }
        }

        return $changes;
    }

    /**
     * @param  list<string>  $stored
     * @param  list<string>  $reported
     * @return list<string>
     */
    private static function union(array $stored, array $reported): array
    {
        $union = array_values(array_unique([...$stored, ...$reported]));
        sort($union);

        return $union;
    }
}
