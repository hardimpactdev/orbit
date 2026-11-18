<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Settle pre-window episodes once, before runtime stops accepting them.
 * Old and new releases both read window evidence. Rollback must not recreate the old representation;
 * only the pre-release database snapshot can restore discarded collector-clock history.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('problem_fingerprints')->orderBy('id')->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $evidence = json_decode($row->evidence, true, flags: JSON_THROW_ON_ERROR);

                    if (! is_array($evidence)) {
                        throw new UnexpectedValueException('Problem evidence must be a JSON object.');
                    }

                    if (array_key_exists('observation_counts', $evidence) || array_key_exists('counted_blocks', $evidence)) {
                        continue;
                    }

                    $dropHistory = in_array($row->source, ['log', 'activity'], true);
                    $windows = $dropHistory ? [] : $this->windows($evidence['observation_times'] ?? null);

                    if ($windows === []) {
                        unset($evidence['observation_times']);
                        $evidence['observation_counts'] = [];
                        $evidence['counted_blocks'] = [];
                    } else {
                        $sample = array_slice($windows, -20, null, true);
                        $evidence['observation_times'] = array_column($sample, 'at');
                        $evidence['observation_counts'] = array_column($sample, 'count');
                        $evidence['counted_blocks'] = array_keys($windows);
                    }

                    $update = ['occurrences' => count($windows), 'evidence' => json_encode($evidence, JSON_THROW_ON_ERROR)];

                    if ($dropHistory) {
                        $update['first_seen'] = null;
                        $update['last_seen'] = null;
                    }

                    DB::table('problem_fingerprints')->where('id', $row->id)->update($update);
                }
            });
        });
    }

    public function down(): void
    {
        // Data conversion is forward-only; retained releases already understand window evidence.
    }

    /** @return array<int, array{at: string, count: int}> */
    private function windows(mixed $times): array
    {
        $windows = [];

        foreach (is_array($times) ? $times : [] as $time) {
            if (! is_string($time) || $time === '') {
                continue;
            }

            try {
                $stamp = Carbon::parse($time)->utc()->getTimestamp();
            } catch (Throwable) {
                continue;
            }

            $block = intdiv($stamp, 300);

            if (isset($windows[$block])) {
                $windows[$block]['count']++;

                continue;
            }

            $windows[$block] = ['at' => $time, 'count' => 1];
        }

        ksort($windows);

        return $windows;
    }
};
