<?php

declare(strict_types=1);

use App\Support\Console\Animation;
use Tests\Support\AnimationCadence;

describe('animation composition cadence', function (): void {
    it('keeps the production frame interval at 300 ms', function (): void {
        expect(Animation::FRAME_INTERVAL_SECONDS)->toBe(0.3);
    });

    it('keeps outer ticks and the first inner phase on cadence across admission and resume', function (): void {
        $process = proc_open([PHP_BINARY, __DIR__.'/../../../app/Support/Console/Renderers/animate.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $send = static function (array $message) use ($pipes): void {
            $payload = json_encode($message, JSON_THROW_ON_ERROR)."\n";
            expect(fwrite($pipes[0], $payload))->toBe(strlen($payload));
            fflush($pipes[0]);
        };
        $outerFrames = ["@outer:0@\n", "@outer:1@\n"];
        $nestedFrames = ["@outer:0@\n@inner:0@\n", "@outer:1@\n@inner:1@\n"];
        $changes = ['outer' => [], 'inner' => []];
        $glyphs = ['outer' => [], 'inner' => []];
        $buffer = '';
        $admitted = false;
        $resumed = false;
        $resumeCount = 0;
        $interval = 0.05;
        $updateDelay = $interval / 4;

        try {
            stream_set_blocking($pipes[1], false);
            $send(['frames' => $outerFrames, 'interval' => $interval]);
            $deadline = microtime(true) + 1.9;

            while (microtime(true) < $deadline) {
                $now = microtime(true);

                if (! $admitted && $changes['outer'] !== [] && $now - $changes['outer'][0] >= $updateDelay) {
                    $send(['frames' => $nestedFrames]);
                    $admitted = true;
                }

                if (! $resumed && count($changes['inner']) >= 3 && $now - $changes['inner'][2] >= $updateDelay) {
                    $send(['frames' => $outerFrames]);
                    $resumed = true;
                    $resumeCount = count($changes['outer']);
                }

                $read = [$pipes[1]];
                $write = $except = [];
                $selected = stream_select($read, $write, $except, 0, 10000);
                expect($selected)->not->toBeFalse();

                if ($selected > 0) {
                    $chunk = fread($pipes[1], 8192);

                    if ($chunk === '' && feof($pipes[1])) {
                        break;
                    }

                    $buffer .= $chunk;
                    $observed = microtime(true);

                    while (($end = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $end);
                        $buffer = substr($buffer, $end + 1);

                        if (preg_match('/@(outer|inner):([01])@/', $line, $match) === 1) {
                            $changes[$match[1]][] = $observed;
                            $glyphs[$match[1]][] = $match[2];
                        }
                    }
                }

                if ($resumed && count($changes['outer']) >= $resumeCount + 3) {
                    break;
                }
            }

            expect($admitted)->toBeTrue()->and($resumed)->toBeTrue()
                ->and(count($changes['outer']))->toBeGreaterThanOrEqual(7)
                ->and(count($changes['inner']))->toBeGreaterThanOrEqual(3)
                ->and(AnimationCadence::isReadable($changes['outer'], $interval))->toBeTrue()
                ->and(AnimationCadence::isReadable($changes['inner'], $interval))->toBeTrue();

            foreach ($glyphs as $sequence) {
                for ($index = 1; $index < count($sequence); $index++) {
                    expect($sequence[$index])->not->toBe($sequence[$index - 1]);
                }
            }

            $send(['final' => ["Finished.\n", "Finished.\n"]]);
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], true);
            expect(stream_get_contents($pipes[1]))->toContain('Finished.', "\e[?25h");
            $receipts = array_filter(explode("\n", trim(stream_get_contents($pipes[3]))));
            expect($receipts)->toHaveCount(3);
            expect(stream_get_contents($pipes[2]))->toBe('');
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }

            $status = proc_get_status($process);

            if ($status['running']) {
                proc_terminate($process);
            }

            $exitCode = proc_close($process);
        }

        expect($status['exitcode'] >= 0 ? $status['exitcode'] : $exitCode)->toBe(0);
    });
});
