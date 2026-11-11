<?php

namespace App\Domain\Instances\Environment;

use RuntimeException;
use Throwable;

// Loaded after DevelopmentMailEnvironment.php, in that resolver's namespace. This file owns byte editing only.
set_error_handler(static function (): never {
    throw new RuntimeException('env.configuration_invalid');
});
try {
    $plan = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $file = $plan['application'].'/.env';
    if (is_link($file) || ! is_file($file)) {
        throw new RuntimeException('env.configuration_invalid');
    }
    $before = file_get_contents($file);
    $stat = stat($file);
    $spans = [];
    $values = [];
    $choices = [];
    $offset = 0;
    $length = strlen($before);
    // Walk whole assignments, including multiline non-mail values, so a MAIL_* line inside one is never mistaken for a key.
    while ($offset < $length) {
        $newline = strpos($before, "\n", $offset);
        $lineEnd = $newline === false ? $length : $newline + 1;
        $line = substr($before, $offset, $lineEnd - $offset);
        if (preg_match('/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*/', $line, $match) !== 1) {
            $offset = $lineEnd;

            continue;
        }
        $key = $match[1];
        $start = $offset + strlen($match[0]);
        $end = $start;
        $quote = $before[$start] ?? '';
        if ($quote === '"' || $quote === "'") {
            $end++;
            $closed = false;
            while ($end < $length) {
                if ($quote === '"' && $before[$end] === '\\') {
                    $end += 2;

                    continue;
                }
                if ($before[$end++] === $quote) {
                    $closed = true;
                    break;
                }
            }
            if (! $closed) {
                throw new RuntimeException('env.configuration_invalid');
            }
            $raw = substr($before, $start + 1, $end - $start - 2);
            $value = $quote === '"' ? strtr($raw, ['\\"' => '"', '\\\\' => '\\', '\\$' => '$']) : $raw;
        } else {
            $end = $newline === false ? $length : $newline;
            if (preg_match('/[ \t]+#|\r?$/', substr($before, $start, $end - $start), $comment, PREG_OFFSET_CAPTURE) === 1) {
                $end = $start + $comment[0][1];
            }
            while ($end > $start && str_contains(" \t\r", $before[$end - 1])) {
                $end--;
            }
            $value = substr($before, $start, $end - $start);
        }
        if (in_array($key, [...DevelopmentMailEnvironment::Keys, 'MAIL_URL'], true)) {
            $values[$key] = $value;
            $choices[$key][] = $value;
            $spans[] = [$key, $start, $end];
        }
        $next = strpos($before, "\n", $end);
        $offset = $next === false ? $length : $next + 1;
    }
    $endpoint = $plan['endpoint'];
    $resolve = static fn (array $input): array => DevelopmentMailEnvironment::resolve($input, $endpoint['host'], $endpoint['port'], $plan['owned'] ?? []);
    $desired = $resolve($values);
    // A duplicate explicit choice must not be erased by a later stock assignment.
    foreach ($choices as $key => $alternatives) {
        foreach ($alternatives as $alternative) {
            if ($resolve([...$values, $key => $alternative]) === []) {
                $desired = [];
            }
        }
    }
    $after = $before;
    $changed = [];
    $seen = [];
    foreach (array_reverse($spans) as [$key, $start, $end]) {
        if (! array_key_exists($key, $desired)) {
            continue;
        }
        $seen[$key] = true;
        // Do not re-quote an already equal value: a repeat preserves even the original quote style.
        if (in_array($desired[$key], $choices[$key], true) && count(array_unique($choices[$key])) === 1) {
            continue;
        }
        $replacement = '"'.$desired[$key].'"';
        $after = substr_replace($after, $replacement, $start, $end - $start);
        $changed[$key] = true;
    }
    $ending = str_contains($before, "\r\n") ? "\r\n" : "\n";
    $missing = array_diff_key($desired, $seen);
    if ($missing !== []) {
        if ($after !== '' && ! str_ends_with($after, "\n")) {
            $after .= $ending;
        }
        foreach ($missing as $key => $value) {
            $after .= $key.'="'.$value.'"'.$ending;
            $changed[$key] = true;
        }
    }
    if ($after !== $before) {
        $backup = $plan['backup'];
        $state = dirname($backup);
        if (! is_dir($state)) {
            mkdir($state, 0700, true);
        }
        $write = static function (string $path, string $content, int $mode) use ($stat): void {
            $temporary = tempnam(dirname($path), '.orbit-mail-');
            try {
                file_put_contents($temporary, $content);
                chmod($temporary, $mode);
                chown($temporary, $stat['uid']);
                chgrp($temporary, $stat['gid']);
                rename($temporary, $path);
            } finally {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        };
        $write($backup, $before, 0600);
        $write($file, $after, $stat['mode'] & 0777);
    }
    echo json_encode(['mail_keys_taken_over' => array_keys($desired), 'changed_key_count' => count($changed),
        'changed' => $after !== $before, 'reason' => $desired === [] ? 'own_mail_settings_kept' : ($changed === [] ? 'mail_already_applied' : 'mail_defaults_applied')], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    echo json_encode(['error_code' => 'env.configuration_invalid']);
    exit(1);
}
