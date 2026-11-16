<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Admission data only: batches from trusted YAML, weighted by the latest measured case times, split by case when a split pays. */
final readonly class ProofBatchPlan
{
    /** The fixed work of one shard: the claim of its VM, the workspace check, the release of the VM and the report. */
    public const int ShardSeconds = 600;

    /** The fixed work of one batch beside its cases: the reset of the fleet, the runner plan, the token check and the batch setup. A read-only
     * batch has none: it changes nothing, so it needs no reset of its own. */
    public const int BatchSeconds = 180;

    /** The longest run budget of one shard. A CI place is held two hours at most, as long as a proof may wait for one. */
    public const int MaxShardSeconds = 7200;

    public function __construct(private ProofCommitSource $source) {}

    /**
     * The read-only cases (`read_only: true`) of a batch without setup form a batch of their own, which runs without a reset.
     *
     * @param  list<array{command:string,file:string,only?:list<string>,reasons?:array<string,string>}>  $commands
     * @return list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float,read_only:bool,case_seconds:list<float>}>
     */
    public function read(string $commit, array $commands): array
    {
        $definitions = $this->yaml($commit, 'swamp/proof/batches.yaml')['batches'] ?? null;
        if (! is_array($definitions) || $definitions === []) {
            throw $this->invalid('The proof batch list is empty or malformed.');
        }
        $times = $this->times();
        $measured = array_values($times);
        sort($measured);
        $fallback = $measured === [] ? 1.0 : ($measured[intdiv(count($measured) - 1, 2)] + $measured[intdiv(count($measured), 2)]) / 2;
        $batches = [];
        foreach ($commands as $command) {
            $spec = $this->yaml($commit, $command['file']);
            if (($spec['command'] ?? null) !== $command['command'] || ! is_array($spec['cases'] ?? null) || $spec['cases'] === []) {
                throw $this->invalid('Selected command '.$command['command'].' has no valid cases.');
            }
            $grouped = [];
            $population = self::population($spec);
            $unchanging = self::readOnly($spec);
            foreach ($population as $id => $batch) {
                if (! array_key_exists($batch, $definitions)) {
                    throw $this->invalid('Selected command '.$command['command'].' has an invalid batch.');
                }
                if (! isset($command['only']) || in_array($id, $command['only'], true)) {
                    $readOnly = isset($unchanging[$id]) && is_array($definitions[$batch]) && ($definitions[$batch]['setup'] ?? []) === [];
                    $grouped[$batch][$readOnly ? 1 : 0][] = $id;
                }
            }
            if ($grouped === [] || (isset($command['only']) && array_diff($command['only'], array_keys($population)) !== [])) {
                throw $this->invalid('Selected command has no matching case ids.');
            }
            foreach ($definitions as $name => $definition) {
                if (! isset($grouped[$name])) {
                    continue;
                }
                if (! is_string($name) || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $name) !== 1 || ! is_array($definition)) {
                    throw $this->invalid('The proof batch list contains an invalid batch.');
                }
                ksort($grouped[$name]);
                foreach ($grouped[$name] as $readOnly => $ids) {
                    $caseSeconds = array_map(static fn (string $id): float => $times[$command['file'].'#'.$id] ?? $fallback, $ids);
                    $batches[] = [...$command, 'batch' => $name, 'cases' => $ids, 'seconds' => array_sum($caseSeconds), 'read_only' => $readOnly === 1,
                        'case_seconds' => $caseSeconds];
                }
            }
        }

        return $batches;
    }

    /**
     * The same expanded IDs serve range projection and batch admission.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,string> case ID => batch
     */
    public static function population(array $spec): array
    {
        if (! is_array($spec['cases'] ?? null) || ! array_is_list($spec['cases'])) {
            throw new ResourceOperationException('proof.selection_invalid', 'Selected command has no valid cases.', 409);
        }
        $population = [];
        $waits = is_array($spec['waits'] ?? null) ? $spec['waits'] : [];
        foreach ($spec['cases'] as $case) {
            if (! is_array($case) || ! is_string($case['id'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $case['id']) !== 1
                || ! is_string($case['batch'] ?? null)) {
                throw new ResourceOperationException('proof.selection_invalid', 'Selected command has an invalid case or batch.', 409);
            }
            // A case that waits for a named reason of the file's `waits` map is never planned (docs/reference/verification-cases).
            if (array_key_exists('waits', $case)) {
                if (! is_string($case['waits']) || ! is_string($waits[$case['waits']] ?? null)) {
                    throw new ResourceOperationException('proof.selection_invalid', 'Case '.$case['id'].' waits for no named reason.', 409);
                }

                continue;
            }
            $variants = $case['variants'] ?? null;
            if ($variants !== null && (! is_array($variants) || ! array_is_list($variants) || $variants === [])) {
                throw new ResourceOperationException('proof.selection_invalid', 'Case '.$case['id'].' has invalid variants.', 409);
            }
            foreach ($variants ?? [null] as $variant) {
                if ($variants !== null && (! is_array($variant) || ! is_string($variant['name'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $variant['name']) !== 1)) {
                    throw new ResourceOperationException('proof.selection_invalid', 'Case '.$case['id'].' has an invalid variant name.', 409);
                }
                $id = $case['id'].($variant === null ? '' : '['.$variant['name'].']');
                if (isset($population[$id])) {
                    throw new ResourceOperationException('proof.selection_invalid', 'Selected command repeats case '.$id.'.', 409);
                }
                $population[$id] = $case['batch'];
            }
        }

        return $population;
    }

    /**
     * The case IDs, with variants, of the cases marked `read_only: true`. The runner refuses a marked case that can change the fleet.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,true>
     */
    private static function readOnly(array $spec): array
    {
        $ids = [];
        // population() has checked the cases, their IDs and their variant names.
        foreach (is_array($spec['cases'] ?? null) ? $spec['cases'] : [] as $case) {
            if (! is_array($case) || ($case['read_only'] ?? false) !== true) {
                continue;
            }
            $variants = $case['variants'] ?? null;
            foreach (is_array($variants) ? $variants : [null] as $variant) {
                $ids[ValidatedData::string($case['id']).(is_array($variant) ? '['.ValidatedData::string($variant['name']).']' : '')] = true;
            }
        }

        return $ids;
    }

    /**
     * Deterministic heaviest-first placement of parts on the copy that ends first. Every allocated place gets work. A shard runs its
     * read-only parts first: they leave the fleet at `base`, so the part after them needs no reset.
     *
     * A batch is one part until a split pays (docs/cli/proof/run.mmd). While a place is free, the heaviest part that is not read-only and
     * has the seconds of two or more cases is cut in two, its cases in file order, where the two sums are closest. The cut stays only when
     * the heaviest copy gets lighter; then the next cut is tried. A case never splits, so its stages stay on one copy.
     *
     * @param  list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float,read_only?:bool,case_seconds?:list<float>}>  $batches
     * @return list<list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float,read_only?:bool,case_seconds?:list<float>}>>
     */
    public static function split(array $batches, int $places): array
    {
        if ($places < 1 || $batches === []) {
            throw new \InvalidArgumentException('A proof split needs work and at least one place.');
        }
        $shards = self::place($batches, $places);
        while (count($batches) < $places) {
            $cuttable = array_filter($batches, static fn (array $batch): bool => ! ($batch['read_only'] ?? false) && count($batch['case_seconds'] ?? []) >= 2);
            $heaviest = array_key_first(self::heaviestFirst($cuttable));
            if ($heaviest === null) {
                break;
            }
            $parts = [...array_values(array_diff_key($batches, [$heaviest => true])), ...self::cut($batches[$heaviest])];
            $placed = self::place($parts, $places);
            if (max(array_map(self::work(...), $placed)) >= max(array_map(self::work(...), $shards))) {
                break;
            }
            [$batches, $shards] = [$parts, $placed];
        }

        return $shards;
    }

    /**
     * @param  non-empty-list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float,read_only?:bool,case_seconds?:list<float>}>  $batches
     * @param  positive-int  $places
     * @return non-empty-list<list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float,read_only?:bool,case_seconds?:list<float>}>>
     */
    private static function place(array $batches, int $places): array
    {
        $shards = array_fill(0, min($places, count($batches)), []);
        foreach (self::heaviestFirst($batches) as $batch) {
            $loads = array_map(static fn (array $shard): float => self::work([...$shard, $batch]), $shards);
            $shards[array_search(min($loads), $loads, true)][] = $batch;
        }

        return array_map(static function (array $shard): array {
            usort($shard, static fn (array $a, array $b): int => ($b['read_only'] ?? false) <=> ($a['read_only'] ?? false));

            return $shard;
        }, $shards);
    }

    /**
     * The batches by weight, heaviest first, with their keys: a part weighs its case seconds and, unless read-only, one reset with setup.
     *
     * @template T of array{file:string,batch:string,cases:list<string>,seconds:float,read_only?:bool}
     *
     * @param  array<int,T>  $batches
     * @return array<int,T>
     */
    private static function heaviestFirst(array $batches): array
    {
        $weight = static fn (array $batch): float => $batch['seconds'] + (($batch['read_only'] ?? false) ? 0 : self::BatchSeconds);
        uasort($batches, static fn (array $a, array $b): int => ($weight($b) <=> $weight($a))
            ?: ([$a['file'], $a['batch'], $a['read_only'] ?? false, $a['cases']] <=> [$b['file'], $b['batch'], $b['read_only'] ?? false, $b['cases']]));

        return $batches;
    }

    /**
     * Two parts of one batch: its cases in file order, cut where the sums of their seconds are closest (the first such cut).
     *
     * @param  array{command:string,file:string,batch:string,cases:list<string>,seconds:float,read_only?:bool,case_seconds?:list<float>}  $batch
     * @return non-empty-list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float,read_only?:bool,case_seconds:list<float>}>
     */
    private static function cut(array $batch): array
    {
        $seconds = $batch['case_seconds'] ?? [];
        $total = array_sum($seconds);
        [$at, $gap] = [1, INF];
        for ($index = 1, $sum = 0.0; $index < count($seconds); $index++) {
            $sum += $seconds[$index - 1];
            if (abs(2 * $sum - $total) < $gap) {
                [$at, $gap] = [$index, abs(2 * $sum - $total)];
            }
        }
        $part = static fn (int $from, ?int $length): array => [...$batch, 'cases' => array_slice($batch['cases'], $from, $length),
            'case_seconds' => array_slice($seconds, $from, $length), 'seconds' => array_sum(array_slice($seconds, $from, $length))];

        return [$part(0, $at), $part($at, null)];
    }

    /**
     * The expected work of one shard beside its fixed shard work: its case seconds and one reset with setup for each batch that is not a
     * read-only batch, at least one. Read-only batches run first and leave the fleet at `base`.
     *
     * @param  list<array{seconds:float,read_only?:bool}>  $assignments
     */
    public static function work(array $assignments): float
    {
        $resets = count(array_filter($assignments, static fn (array $batch): bool => ! ($batch['read_only'] ?? false)));

        return max(1, $resets) * self::BatchSeconds + array_sum(array_column($assignments, 'seconds'));
    }

    /**
     * The run budget of one shard, from its assigned batches: the fixed work of the shard, its {@see work}, times 1.5, rounded up to five minutes. It is at most {@see MaxShardSeconds}.
     *
     * @param  list<array{seconds:float}>  $assignments
     */
    public static function budget(array $assignments): int
    {
        return min(self::MaxShardSeconds, self::seconds($assignments));
    }

    /**
     * Whether a shard is planned beyond the longest shard budget. A shard of one part cannot be split further; any larger one must
     * wait for another place instead, so that it does not run out of its budget half way.
     *
     * @param  list<array{seconds:float}>  $assignments
     */
    public static function overBudget(array $assignments): bool
    {
        return count($assignments) > 1 && self::seconds($assignments) > self::MaxShardSeconds;
    }

    /** @param  list<array{seconds:float,read_only?:bool}>  $assignments */
    private static function seconds(array $assignments): int
    {
        return (int) ceil((self::ShardSeconds + self::work($assignments)) * 1.5 / 300) * 300;
    }

    /**
     * Typed assignments read back from the recorded admission. A plan from {@see read} also keeps the seconds of each case, so a retry can split it.
     *
     * @return list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float,read_only:bool,case_seconds?:list<float>}>
     */
    public static function stored(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException('Proof assignments must be a list.');
        }
        $assignments = [];
        foreach ($value as $item) {
            $item = ValidatedData::object($item);
            $seconds = $item['seconds'] ?? 0;
            if (! is_int($seconds) && ! is_float($seconds)) {
                throw new \InvalidArgumentException('Proof batch time must be numeric.');
            }
            $readOnly = $item['read_only'] ?? false;
            if (! is_bool($readOnly)) {
                throw new \InvalidArgumentException('Proof batch read_only must be true or false.');
            }
            $cases = ValidatedData::stringList($item['cases'] ?? []);
            $caseSeconds = $item['case_seconds'] ?? null;
            if ($caseSeconds !== null && (! is_array($caseSeconds) || ! array_is_list($caseSeconds) || count($caseSeconds) !== count($cases)
                || array_filter($caseSeconds, static fn (mixed $value): bool => ! is_int($value) && ! is_float($value)) !== [])) {
                throw new \InvalidArgumentException('Proof case times must be one number for each case.');
            }
            $assignments[] = ['command' => ValidatedData::string($item['command']), 'file' => ValidatedData::string($item['file']),
                'batch' => ValidatedData::string($item['batch']), 'cases' => $cases, 'seconds' => (float) $seconds,
                'read_only' => $readOnly, ...($caseSeconds === null ? [] : ['case_seconds' => array_map(floatval(...), $caseSeconds)])];
        }

        return $assignments;
    }

    /** @return array<string,float> latest positive measurement for each case, bounded to recent proof runs */
    private function times(): array
    {
        $times = [];
        foreach (Operation::query()->where('kind', ProofGroup::OwnerKind)->whereNotNull('result')->orderByDesc('id')->limit(200)->get(['result']) as $run) {
            $cases = $run->result['cases'] ?? [];
            foreach (is_array($cases) ? $cases : [] as $case) {
                if (is_array($case) && is_string($case['program'] ?? null) && is_numeric($case['seconds'] ?? null) && (float) $case['seconds'] > 0) {
                    $times[$case['program']] ??= (float) $case['seconds'];
                }
            }
        }

        return $times;
    }

    /** @return array<string,mixed> */
    private function yaml(string $commit, string $path): array
    {
        try {
            $value = Yaml::parse($this->source->file($commit, $path) ?? '');
        } catch (ParseException) {
            throw $this->invalid('Malformed proof YAML: '.$path.'.');
        }
        if (! is_array($value) || array_is_list($value)) {
            throw $this->invalid('Missing or malformed proof YAML: '.$path.'.');
        }

        return ValidatedData::object($value);
    }

    private function invalid(string $message): ResourceOperationException
    {
        return new ResourceOperationException('proof.selection_invalid', $message, 409);
    }
}
