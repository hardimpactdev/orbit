<?php

declare(strict_types=1);

use App\Support\Console\Animation;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleMode;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressState;
use App\Support\Console\TerminalRegion;
use Symfony\Component\Console\Output\StreamOutput;

pest()->group('subprocess');

/** @param list<float> $changes */
function animation_cadence_is_readable(array $changes): bool
{
    if (count($changes) < 3) {
        return false;
    }

    for ($index = 1; $index < count($changes); $index++) {
        $interval = $changes[$index] - $changes[$index - 1];

        if ($interval < 0.18 || $interval > 0.65) {
            return false;
        }
    }

    return true;
}

describe('animation composition cadence', function (): void {
    it('rejects the observed fast resume interval while accepting a readable phase', function (): void {
        expect(animation_cadence_is_readable([0.0, 0.3, 0.6, 0.9]))->toBeTrue()
            ->and(animation_cadence_is_readable([0.0, 0.3, 0.3664124, 0.6664124]))->toBeFalse()
            ->and(animation_cadence_is_readable([0.0, 0.0412849, 0.3412849]))->toBeFalse()
            ->and(animation_cadence_is_readable([0.0, 0.3, 1.0]))->toBeFalse();
    });

    it('keeps outer ticks and the first inner phase readable across admission and resume', function (): void {
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

        try {
            stream_set_blocking($pipes[1], false);
            $send(['frames' => $outerFrames, 'interval' => Animation::FRAME_INTERVAL_SECONDS]);
            $deadline = microtime(true) + 4;

            while (microtime(true) < $deadline) {
                $now = microtime(true);

                if (! $admitted && $changes['outer'] !== [] && $now - $changes['outer'][0] >= 0.08) {
                    $send(['frames' => $nestedFrames]);
                    $admitted = true;
                }

                if (! $resumed && count($changes['inner']) >= 3 && $now - $changes['inner'][2] >= 0.08) {
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
                ->and(animation_cadence_is_readable($changes['outer']))->toBeTrue()
                ->and(animation_cadence_is_readable($changes['inner']))->toBeTrue();

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

    it('prints a renderer line and its current frame in one write, tick cadence untouched (F4)', function (bool $reverse): void {
        $process = proc_open([PHP_BINARY, __DIR__.'/../../../app/Support/Console/Renderers/animate.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $send = static function (array $message) use ($pipes): void {
            $payload = json_encode($message, JSON_THROW_ON_ERROR)."\n";
            expect(fwrite($pipes[0], $payload))->toBe(strlen($payload));
            fflush($pipes[0]);
        };
        $frames = $reverse ? ["@tick:1@\n", "@tick:0@\n"] : ["@tick:0@\n", "@tick:1@\n"];
        $buffer = '';
        $changes = [];

        try {
            stream_set_blocking($pipes[1], false);
            $send(['frames' => $frames, 'interval' => Animation::FRAME_INTERVAL_SECONDS]);
            $deadline = microtime(true) + 3;
            $linesSent = 0;

            while (microtime(true) < $deadline) {
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

                    if ($linesSent < 3 && str_contains($buffer, '@tick:')) {
                        $send(['line' => "LINE-{$linesSent}\n"]);
                        $linesSent++;
                    }

                    while (($end = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $end);
                        $buffer = substr($buffer, $end + 1);
                        $observed = microtime(true);

                        // A line-print reprints the same (unchanged) glyph; only count an
                        // actual toggle, the same tick loop the real ticks produce.
                        if (preg_match('/@tick:([01])@/', $line, $match) === 1
                            && ($changes === [] || $changes[count($changes) - 1]['g'] !== $match[1])) {
                            $changes[] = ['t' => $observed, 'g' => $match[1]];
                        }
                    }
                }

                if ($linesSent >= 3 && count($changes) >= 5) {
                    break;
                }
            }

            expect($linesSent)->toBe(3)->and(count($changes))->toBeGreaterThanOrEqual(5);
            $intervals = [];

            for ($i = 1; $i < count($changes); $i++) {
                $intervals[] = $changes[$i]['t'] - $changes[$i - 1]['t'];
            }

            // The tick loop is untouched by line printing: no hold near 0.9s, no burst near 0s.
            foreach ($intervals as $interval) {
                expect($interval)->toBeGreaterThan(0.15)->toBeLessThan(0.65);
            }

            $send(['final' => ["Finished.\n", "Finished.\n"]]);
            fclose($pipes[0]);
            unset($pipes[0]);
            stream_set_blocking($pipes[1], true);
            $tail = stream_get_contents($pipes[1]);
            expect($tail)->toContain('Finished.', "\e[?25h");
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
    })->with([false, true]);

    it('shows every printed line above the tree in order, with escaping preserved, and no absent-tree frame (F4)', function (): void {
        $stream = tmpfile();
        $output = new StreamOutput($stream);
        $mode = new ConsoleMode(false, false, true, true, 80);
        $animation = new Animation($mode, $output, ["@frame:0@\n", "@frame:1@\n"]);

        try {
            $animation->during(function () use ($output): int {
                Animation::printLine($output, "first line\n");
                Animation::printLine($output, "second \e[31mline\e[0m\n");
                Animation::printLine($output, "third line\n");

                return 1;
            });

            rewind($stream);
            $bytes = stream_get_contents($stream);

            // Every printed line is immediately followed by the current frame, in one write
            // each: the tree is never absent from the recorded output while lines stream.
            expect($bytes)->toContain("first line\n@frame:")
                ->and($bytes)->toContain("second \e[31mline\e[0m\n@frame:")
                ->and($bytes)->toContain("third line\n@frame:");

            $firstAt = strpos($bytes, 'first line');
            $secondAt = strpos($bytes, 'second');
            $thirdAt = strpos($bytes, 'third line');
            expect($firstAt)->not->toBeFalse()->and($secondAt)->toBeGreaterThan($firstAt)
                ->and($thirdAt)->toBeGreaterThan($secondAt);

            // No line ever appears without a frame following the very next content: the two
            // are always adjacent, never separated by a bare clear-and-restart cycle.
            expect(substr_count($bytes, "\e[?25l"))->toBe(1)
                ->and(substr_count($bytes, "\e[?25h"))->toBe(1);
        } finally {
            fclose($stream);
        }
    });

    it('falls back to a plain write with no repeated frames outside an active renderer (F4)', function (): void {
        $stream = tmpfile();
        $output = new StreamOutput($stream);

        Animation::printLine($output, "plain line one\n");
        Animation::printLine($output, "plain line two\n");

        rewind($stream);
        $bytes = stream_get_contents($stream);

        expect($bytes)->toBe("plain line one\nplain line two\n")
            ->and($bytes)->not->toContain("\e[");
    });

    it('restores the terminal after printLine on success, product failure, and interrupt (F4)', function (bool $succeeds, bool $interrupted): void {
        $stream = tmpfile();
        $output = new StreamOutput($stream);
        $mode = new ConsoleMode(false, false, true, true, 80);
        $animation = new Animation($mode, $output, ["@frame:0@\n", "@frame:1@\n"]);

        try {
            if ($succeeds) {
                $animation->during(function () use ($output): int {
                    Animation::printLine($output, "output line\n");

                    return 1;
                });
            } else {
                try {
                    $animation->during(function () use ($output, $interrupted): never {
                        Animation::printLine($output, "output line\n");

                        throw $interrupted
                            ? new ConsoleInterrupted(SIGINT)
                            : new RuntimeException('product failure');
                    });
                } catch (Throwable) {
                    // Expected: the point of this case is what happens to the terminal, not
                    // whether the exception itself propagates (it always does, unchanged).
                }
            }

            rewind($stream);
            $bytes = stream_get_contents($stream);
            expect($bytes)->toContain('output line')
                ->and(substr_count($bytes, "\e[?25l"))->toBe(1)
                ->and($bytes)->toEndWith("\e[0m\e[?25h");
        } finally {
            fclose($stream);
        }
    })->with([
        'succeeds' => [true, false],
        'product failure' => [false, false],
        'interrupted' => [false, true],
    ]);

    it('transfers one render owner through nested callbacks and settled region updates', function (): void {
        $stream = tmpfile();
        $output = new StreamOutput($stream);
        $mode = new ConsoleMode(false, false, true, true, 80);
        $outer = new Animation($mode, $output, ["outer 0\n", "outer 1\n"]);
        $region = new TerminalRegion($mode, $output);
        $inner = new Animation($mode, $output, ["inner 0\n", "inner 1\n"], region: $region,
            settled: static fn (): string => "Inner finished.\n");
        $property = new ReflectionProperty(Animation::class, 'process');
        $calls = [];
        $rendererPid = null;

        try {
            $result = $outer->during(function () use ($outer, $inner, $region, $property, &$calls, &$rendererPid): int {
                $calls[] = getmypid();
                $process = $property->getValue($outer);
                $rendererPid = proc_get_status($process)['pid'];
                $value = $inner->during(function () use ($outer, $inner, $property, $rendererPid, &$calls): int {
                    $calls[] = getmypid();
                    expect($property->getValue($outer))->toBeNull()
                        ->and(proc_get_status($property->getValue($inner))['pid'])->toBe($rendererPid);

                    return 42;
                });
                expect($property->getValue($inner))->toBeNull()
                    ->and(proc_get_status($property->getValue($outer))['pid'])->toBe($rendererPid);
                $region->replace("Inner verified.\n");
                expect(proc_get_status($property->getValue($outer))['pid'])->toBe($rendererPid);

                return $value;
            });

            expect($result)->toBe(42)->and($calls)->toBe([getmypid(), getmypid()])
                ->and($property->getValue($outer))->toBeNull();
            rewind($stream);
            $outputBytes = stream_get_contents($stream);
            expect($outputBytes)->toContain('Inner finished.', 'Inner verified.')
                ->and(substr_count($outputBytes, "\e[?25l"))->toBe(1)
                ->and(substr_count($outputBytes, "\e[?25h"))->toBe(1);

            if (function_exists('posix_kill')) {
                expect(posix_kill($rendererPid, 0))->toBeFalse();
            }
        } finally {
            fclose($stream);
        }
    });

    it('inherits the renderer interval when a nested callback restarts presentation', function (): void {
        $stream = tmpfile();
        $path = stream_get_meta_data($stream)['uri'];
        $output = new StreamOutput($stream);
        $mode = new ConsoleMode(false, false, true, true, 80);
        $outer = new Animation($mode, $output, ["Outer\n", "Outer\n"], frameIntervalSeconds: 0.01);
        $inner = new Animation($mode, $output, ["@inner:0@\n", "@inner:1@\n"]);

        try {
            $outer->during(function () use ($inner, $path): void {
                $inner->during(function () use ($path): void {
                    $offset = strlen(file_get_contents($path));
                    Animation::withoutRepainting(static fn (): null => null);
                    $deadline = microtime(true) + 0.2;
                    $phases = [];

                    do {
                        preg_match_all('/@inner:([01])@/', substr(file_get_contents($path), $offset), $matches);
                        $phases = [];

                        foreach ($matches[1] as $phase) {
                            if ($phases === [] || $phase !== $phases[array_key_last($phases)]) {
                                $phases[] = $phase;
                            }
                        }

                        if (count($phases) >= 3) {
                            break;
                        }

                        usleep(1000);
                    } while (microtime(true) < $deadline);

                    expect(count($phases))->toBeGreaterThanOrEqual(3);
                });
            });
        } finally {
            fclose($stream);
        }
    });

    it('rejects invalid renderer intervals before acknowledging presentation', function (mixed $interval): void {
        $process = proc_open([PHP_BINARY, __DIR__.'/../../../app/Support/Console/Renderers/animate.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();

        try {
            fwrite($pipes[0], json_encode(['frames' => ["Waiting 0\n", "Waiting 1\n"], 'interval' => $interval], JSON_THROW_ON_ERROR)."\n");
            fclose($pipes[0]);
            $bytes = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            $receipt = stream_get_contents($pipes[3]);
            expect(proc_close($process))->toBe(1)
                ->and($receipt)->toBe('')
                ->and($errors)->toBe('')
                ->and($bytes)->toContain("\e[?25h")->not->toContain('Waiting');
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    })->with([0.0, -0.01, '0.01', null]);

    it('starts and settles cold nested progress in the outer callback and restores signal state', function (): void {
        $stream = tmpfile();
        $output = new StreamOutput($stream);
        $mode = new ConsoleMode(false, false, true, true, 80);
        $async = pcntl_async_signals();
        $handler = pcntl_signal_get_handler(SIGTERM);
        $outer = new ProgressDisplay($mode, $output, 'Outer operation', frameIntervalSeconds: 0.01);
        $active = new ReflectionProperty(Animation::class, 'active');
        $process = new ReflectionProperty(Animation::class, 'process');
        $calls = [];
        $rendererPid = null;

        try {
            $outer->admit('update', 'Update', 'Updating', 'Updated');
            $result = $outer->during('update', function () use ($mode, $output, $active, $process, &$calls, &$rendererPid): int {
                $calls[] = getmypid();
                $owner = $active->getValue();
                $rendererPid = proc_get_status($process->getValue($owner))['pid'];
                $inner = new ProgressDisplay($mode, $output, 'Inner operation');
                $inner->admit('verify', 'Verify', 'Verifying', 'Verified');
                $value = $inner->during('verify', function () use ($active, $process, $rendererPid, &$calls): int {
                    $calls[] = getmypid();
                    expect(proc_get_status($process->getValue($active->getValue()))['pid'])->toBe($rendererPid);

                    return 42;
                });
                $inner->complete('verify', ProgressState::Success);
                $inner->finish('Inner verified.');
                expect($active->getValue())->toBe($owner)
                    ->and(proc_get_status($process->getValue($owner))['pid'])->toBe($rendererPid);

                return $value;
            });
            $outer->complete('update', ProgressState::Success);
            $outer->finish('Resource updated.');
            rewind($stream);
            $bytes = stream_get_contents($stream);
            expect($result)->toBe(42)->and($calls)->toBe([getmypid(), getmypid()])
                ->and(pcntl_async_signals())->toBe($async)
                ->and(pcntl_signal_get_handler(SIGTERM))->toBe($handler)
                ->and($bytes)->toContain('Inner verified.', 'Resource updated.')
                ->not->toContain('Private fixture detail')
                ->and(substr_count($bytes, "\e[?25l"))->toBe(1)
                ->and(substr_count($bytes, "\e[?25h"))->toBe(1);

            if (function_exists('posix_kill')) {
                expect(posix_kill($rendererPid, 0))->toBeFalse();
            }
        } finally {
            fclose($stream);
        }
    });

    it('preserves the last active glyph when stopping before explicit product settlement', function (bool $terminal): void {
        $stream = tmpfile();
        $path = stream_get_meta_data($stream)['uri'];
        $output = new StreamOutput($stream);
        $mode = new ConsoleMode(false, false, true, true, 80);
        $frames = ["@outer:0@\n", "@outer:1@\n"];
        $animation = new Animation($mode, $output, $frames,
            settled: static fn (): string => $terminal ? "Finished.\n" : $frames[0]);

        try {
            $result = $animation->during(function () use ($path): int {
                $deadline = microtime(true) + 2;

                do {
                    if (str_contains(file_get_contents($path), '@outer:1@')) {
                        return 42;
                    }

                    usleep(5000);
                } while (microtime(true) < $deadline);

                throw new RuntimeException('Renderer did not paint the alternate glyph.');
            });
            $bytes = file_get_contents($path);
            preg_match_all('/@outer:([01])@/', $bytes, $matches);
            $glyphs = $matches[1];
            expect($result)->toBe(42)->and(count($glyphs))->toBeGreaterThanOrEqual(2)
                ->and($bytes)->toEndWith("\e[0m\e[?25h");

            if ($terminal) {
                expect($bytes)->toContain("Finished.\n");

                return;
            }

            expect(count($glyphs))->toBeGreaterThanOrEqual(3)
                ->and($glyphs[count($glyphs) - 1])->toBe($glyphs[count($glyphs) - 2])
                ->and($bytes)->not->toContain('Finished.');
        } finally {
            fclose($stream);
        }
    })->with(['awaiting product evidence' => false, 'explicit terminal result' => true]);
});
