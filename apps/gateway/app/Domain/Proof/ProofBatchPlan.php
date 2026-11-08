<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Admission data only: whole batches from trusted YAML, weighted by the latest measured case times. */
final readonly class ProofBatchPlan
{
    public function __construct(private ProofCommitSource $source) {}

    /**
     * @param  list<array{command:string,file:string}>  $commands
     * @return list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float}>
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
        $fallback = $measured === [] ? 1.0 : $measured[intdiv(count($measured), 2)];
        $batches = [];
        foreach ($commands as $command) {
            $spec = $this->yaml($commit, $command['file']);
            if (($spec['command'] ?? null) !== $command['command'] || ! is_array($spec['cases'] ?? null) || $spec['cases'] === []) {
                throw $this->invalid('Selected command '.$command['command'].' has no valid cases.');
            }
            $grouped = [];
            $seen = [];
            foreach ($spec['cases'] as $case) {
                if (! is_array($case) || ! is_string($case['id'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $case['id']) !== 1
                    || ! is_string($case['batch'] ?? null) || ! array_key_exists($case['batch'], $definitions)) {
                    throw $this->invalid('Selected command '.$command['command'].' has an invalid case or batch.');
                }
                $variants = $case['variants'] ?? null;
                if ($variants !== null && (! is_array($variants) || ! array_is_list($variants) || $variants === [])) {
                    throw $this->invalid('Case '.$case['id'].' has invalid variants.');
                }
                foreach ($variants ?? [null] as $variant) {
                    if ($variants !== null && (! is_array($variant) || ! is_string($variant['name'] ?? null) || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $variant['name']) !== 1)) {
                        throw $this->invalid('Case '.$case['id'].' has an invalid variant name.');
                    }
                    $id = $case['id'].($variant === null ? '' : '['.$variant['name'].']');
                    if (isset($seen[$id])) {
                        throw $this->invalid('Selected command '.$command['command'].' repeats case '.$id.'.');
                    }
                    $seen[$id] = true;
                    $grouped[$case['batch']][] = $id;
                }
            }
            foreach ($definitions as $name => $definition) {
                if (! isset($grouped[$name])) {
                    continue;
                }
                if (! is_string($name) || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $name) !== 1 || ! is_array($definition)) {
                    throw $this->invalid('The proof batch list contains an invalid batch.');
                }
                $seconds = 0.0;
                foreach ($grouped[$name] as $id) {
                    $seconds += $times[$command['file'].'#'.$id] ?? $fallback;
                }
                $batches[] = [...$command, 'batch' => $name, 'cases' => $grouped[$name], 'seconds' => $seconds];
            }
        }

        return $batches;
    }

    /**
     * Deterministic longest-first placement. No batch is split and every allocated place gets work.
     *
     * @param  list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float}>  $batches
     * @return list<list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float}>>
     */
    public static function split(array $batches, int $places): array
    {
        $places = min($places, count($batches));
        if ($places < 1) {
            throw new \InvalidArgumentException('A proof split needs work and at least one place.');
        }
        usort($batches, static fn (array $a, array $b): int => ($b['seconds'] <=> $a['seconds']) ?: ([$a['file'], $a['batch']] <=> [$b['file'], $b['batch']]));
        $shards = array_fill(0, $places, []);
        $loads = array_fill(0, $places, 0.0);
        foreach ($batches as $batch) {
            $index = array_search(min($loads), $loads, true);
            $shards[$index][] = $batch;
            $loads[$index] += $batch['seconds'];
        }

        return $shards;
    }

    /**
     * Typed assignments read back from the recorded admission.
     *
     * @return list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float}>
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
            $assignments[] = ['command' => ValidatedData::string($item['command']), 'file' => ValidatedData::string($item['file']),
                'batch' => ValidatedData::string($item['batch']), 'cases' => ValidatedData::stringList($item['cases'] ?? []), 'seconds' => (float) $seconds];
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
