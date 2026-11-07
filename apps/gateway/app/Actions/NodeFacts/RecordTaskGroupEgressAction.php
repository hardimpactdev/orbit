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

/**
 * Adds the rows of an `egress` fact to the task group that holds the VM it names (docs/reference/task-vm-egress-log.md).
 * A VM no group holds, such as a base VM, adds nothing. A row is added once for each destination, port and protocol;
 * a later report adds its addresses, sources and flags and never removes anything, so the group keeps its list after its
 * VM and Node are deleted. A row that the agent first saw before the group claimed the VM is not the group's and is left out.
 * An unchanged report reads rows and writes nothing.
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
        $claimed = $vm->claimed_at === null ? null : CarbonImmutable::instance($vm->claimed_at);
        $reported = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            $row = ValidatedData::object($row);
            $firstSeen = CarbonImmutable::parse(ValidatedData::string($row['first_seen']));
            if ($claimed instanceof CarbonImmutable && $firstSeen->lt($claimed)) {
                continue;
            }
            $port = is_int($row['port'] ?? null) ? $row['port'] : 0;
            $protocol = is_string($row['protocol'] ?? null) ? $row['protocol'] : '';
            $reported[ValidatedData::string($row['destination'])."\0".$port."\0".$protocol] = [
                'destination' => ValidatedData::string($row['destination']),
                'kind' => ValidatedData::string($row['kind']),
                'port' => $port,
                'protocol' => $protocol,
                'addresses' => ValidatedData::stringList($row['addresses'] ?? []),
                'sources' => ValidatedData::stringList($row['sources'] ?? []),
                'flags' => ValidatedData::stringList($row['flags'] ?? []),
                'first_seen_at' => $firstSeen,
            ];
        }
        if ($reported === []) {
            return 0;
        }

        return DB::transaction(function () use ($vm, $reported): int {
            $groupId = (int) $vm->group_id;
            $stored = TaskGroupEgress::query()->where('task_id', $groupId)->get()
                ->keyBy(static fn (TaskGroupEgress $row): string => $row->destination."\0".$row->port."\0".$row->protocol);
            $room = self::MaxRows - $stored->count();
            $written = 0;
            foreach ($reported as $key => $row) {
                $existing = $stored->get($key);
                if (! $existing instanceof TaskGroupEgress) {
                    if ($room <= 0) {
                        continue;
                    }
                    TaskGroupEgress::query()->create(['task_id' => $groupId, 'vm_name' => $vm->name, ...$row]);
                    $room--;
                    $written++;

                    continue;
                }
                $merged = [
                    'addresses' => array_slice(self::union($existing->addresses, $row['addresses']), 0, self::MaxAddresses),
                    'sources' => self::union($existing->sources, $row['sources']),
                    'flags' => self::union($existing->flags, $row['flags']),
                ];
                if ($merged['addresses'] !== $existing->addresses || $merged['sources'] !== $existing->sources || $merged['flags'] !== $existing->flags) {
                    $existing->update($merged);
                    $written++;
                }
            }

            return $written;
        });
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
