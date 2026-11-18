<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

/**
 * SPIKE: a PHP port of swamp/extensions/behaviors/operations/firewall/plan.ts.
 *
 * Plans and checks one Orbit allow or deny from `ufw status numbered`. Lines match by tuple; UFW stops at the first
 * match, so new lines go above Orbit's managed lines (comment `orbit:<name>`) and broader operator lines, below
 * narrower ones. Each address family is placed on its own.
 */
final class UfwPlan
{
    private const UFW = '/usr/sbin/ufw';

    /** @return list<list<string>> the ufw argv lists that converge the rule, in order (the Ansible path) */
    public static function plan(array $intent, string $stdout): array
    {
        return array_map(static function (array $change): array {
            $rule = [$change['rule'], 'in', 'proto', $change['proto'], 'from', $change['from'], 'to', 'any', 'port', $change['port']];
            if ($change['delete']) {
                return ['timeout', '60', self::UFW, '--force', 'delete', ...$rule];
            }
            $insert = $change['position'] === null ? [] : ['insert', (string) $change['position']];

            return ['timeout', '60', self::UFW, ...$insert, ...$rule, 'comment', $change['comment']];
        }, self::changes($intent, $stdout));
    }

    /**
     * The typed changes that converge the rule, in order; positions count the rows as they will be. Each is the input of
     * one `ufw.ensure` primitive (the executor path).
     *
     * @return list<array{rule: string, port: string, proto: string, from: string, comment: string, position: ?int, delete: bool}>
     */
    public static function changes(array $intent, string $stdout): array
    {
        [$rule, $ensure] = self::validated($intent);
        $rows = self::inventory($stdout);
        $commands = [];
        $change = fn (string $protocol, string $source, ?int $position, bool $delete): array => ['rule' => $rule['action'],
            'port' => $rule['port'], 'proto' => $protocol, 'from' => $source, 'comment' => $rule['comment'],
            'position' => $position, 'delete' => $delete];
        foreach ($rule['protocols'] as $protocol) {
            foreach (self::lines($rule, $protocol) as [$source, $row]) {
                $exists = self::has($rows, $row);
                if ($ensure === 'absent') {
                    if ($exists && ! $rule['retain']) {
                        $commands[] = $change($protocol, $source, null, true);
                        $rows = self::without($rows, $row);
                    }

                    continue;
                }
                if ($exists && ! self::ordered($rows, [self::key($row) => true])) {
                    $commands[] = $change($protocol, $source, null, true);
                    $rows = self::without($rows, $row);
                    $exists = false;
                }
                if ($exists) {
                    continue;
                }
                $index = self::firstIndex($rows, fn (array $other): bool => self::below($other, [...$row, 'comment' => '']));
                if ($index >= 0) {
                    $commands[] = $change($protocol, $source, $index + 1, false);
                    array_splice($rows, $index, 0, [$row]);
                } else {
                    $commands[] = $change($protocol, $source, null, false);
                    // ufw appends an IPv4 line after the last IPv4 line and an IPv6 line at the end.
                    $end = self::v6($row) ? count($rows) : self::firstIndex($rows, fn (array $r): bool => self::v6($r));
                    array_splice($rows, $end < 0 ? count($rows) : $end, 0, [$row]);
                }
            }
        }

        return $commands;
    }

    /** @return array{backend: string, skipped?: string} */
    public static function check(array $intent, string $stdout, int $changes = 1): array
    {
        [$rule, $ensure] = self::validated($intent);
        $wanted = [];
        foreach ($rule['protocols'] as $protocol) {
            foreach (self::lines($rule, $protocol) as [, $row]) {
                $wanted[self::key($row)] = true;
            }
        }
        $rows = self::inventory($stdout);
        $present = count(array_filter($rows, fn (array $row): bool => isset($wanted[self::key($row)])));
        if ($ensure === 'absent') {
            if (! $rule['retain'] && $present > 0) {
                throw new UfwRefusal('firewall.remove_verify_failed');
            }

            return ['backend' => 'absent'];
        }
        if ($present !== count($wanted) || ! self::ordered($rows, $wanted)) {
            throw new UfwRefusal('firewall.verify_failed');
        }

        return $changes === 0 ? ['backend' => 'active', 'skipped' => 'firewall.rule_exists'] : ['backend' => 'active'];
    }

    /** @return list<array{to: string, action: string, source: string, comment: string, direction?: string}> */
    public static function inventory(string $stdout): array
    {
        $lines = explode("\n", $stdout);
        if (count(array_filter($lines, fn (string $line): bool => trim($line) === 'Status: active')) !== 1) {
            throw new UfwRefusal('firewall.backend_inactive');
        }
        $rows = [];
        foreach ($lines as $line) {
            if (! preg_match('/^\[\s*\d+\]\s+(.+)$/', trim($line), $numbered)) {
                continue;
            }
            // Every numbered row occupies an insert position, including unrelated actions and directions.
            if (preg_match('/^(.+?)\s+(ALLOW|DENY|LIMIT|REJECT)(?:\s+(IN|OUT|FWD))?\s+(.+?)(?:\s+#\s*(.*))?$/', $numbered[1], $m)) {
                $rows[] = [
                    'to' => trim($m[1]),
                    'action' => strtolower($m[2]),
                    'direction' => strtolower(($m[3] ?? '') !== '' ? $m[3] : 'IN'),
                    'source' => trim($m[4]),
                    'comment' => trim($m[5] ?? ''),
                ];
            } else {
                $rows[] = ['to' => $numbered[1], 'action' => '', 'source' => '', 'comment' => ''];
            }
        }

        return $rows;
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private static function validated(array $intent): array
    {
        $rule = $intent['rule'] ?? null;
        $ensure = $intent['ensure'] ?? null;
        $valid = is_array($rule)
            && in_array($ensure, ['present', 'absent'], true)
            && in_array($rule['action'] ?? null, ['allow', 'deny'], true)
            && is_string($rule['port'] ?? null) && preg_match('/^\d{1,5}(?::\d{1,5})?$/', $rule['port'])
            && in_array($rule['protocol'] ?? null, ['tcp', 'udp', 'any'], true)
            && is_string($rule['source'] ?? null)
            && is_string($rule['comment'] ?? null) && preg_match('/^orbit(?: [\x20-\x7e]{1,64})?$/', $rule['comment'])
            && ! preg_match('/[\'"]/', $rule['comment']);
        if (! $valid) {
            throw new UfwRefusal('firewall.input_invalid');
        }
        $parts = array_map('intval', explode(':', $rule['port']));
        [$first, $last] = [$parts[0], $parts[1] ?? $parts[0]];
        if (! (1 <= $first && $first <= $last && $last <= 65535)) {
            throw new UfwRefusal('firewall.input_invalid');
        }
        if ($rule['source'] !== 'any') {
            self::network($rule['source']);
        }
        $rule['retain'] = (bool) ($rule['retain'] ?? false);
        $rule['protocols'] = $rule['protocol'] === 'any' ? ['tcp', 'udp'] : [$rule['protocol']];

        return [$rule, $ensure];
    }

    /** @return array{six: bool, base: string, bits: int} base is the masked address in network byte order */
    private static function network(string $text): array
    {
        $parts = explode('/', $text, 2);
        $address = $parts[0];
        $six = str_contains($address, ':');
        $packed = @inet_pton($address);
        if ($packed === false || strlen($packed) !== ($six ? 16 : 4)) {
            throw new UfwRefusal('firewall.input_invalid');
        }
        $width = $six ? 128 : 32;
        if (isset($parts[1]) && ! preg_match('/^\d+$/', $parts[1])) {
            throw new UfwRefusal('firewall.input_invalid');
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : $width;
        if ($bits < 0 || $bits > $width) {
            throw new UfwRefusal('firewall.input_invalid');
        }

        return ['six' => $six, 'base' => self::mask($packed, $bits), 'bits' => $bits];
    }

    private static function mask(string $packed, int $bits): string
    {
        $out = '';
        foreach (str_split($packed) as $i => $byte) {
            $keep = max(0, min(8, $bits - $i * 8));
            $out .= chr(ord($byte) & ((0xFF << (8 - $keep)) & 0xFF));
        }

        return $out;
    }

    private static function contains(array $wide, array $narrow): bool
    {
        if ($wide['six'] !== $narrow['six'] || $narrow['bits'] < $wide['bits']) {
            return false;
        }

        return self::mask($narrow['base'], $wide['bits']) === $wide['base'];
    }

    /** @return list<array{0: string, 1: array}> (argument for `from`, `ufw status` row) per family */
    private static function lines(array $rule, string $protocol): array
    {
        $to = "{$rule['port']}/{$protocol}";
        $row = fn (string $source, string $suffix, string $shown): array => [
            $source,
            ['to' => $to.$suffix, 'action' => $rule['action'], 'source' => $shown, 'comment' => $rule['comment']],
        ];
        if ($rule['source'] === 'any') {
            return [$row('0.0.0.0/0', '', 'Anywhere'), $row('::/0', ' (v6)', 'Anywhere (v6)')];
        }
        $source = self::network($rule['source']);
        if ($source['bits'] === 0) {
            return [$row($rule['source'], $source['six'] ? ' (v6)' : '', $source['six'] ? 'Anywhere (v6)' : 'Anywhere')];
        }

        // ufw marks only a line for anywhere with `(v6)`; a line from one IPv6 address shows the address alone.
        return [$row($rule['source'], '', $rule['source'])];
    }

    private static function incoming(array $row): bool
    {
        return ($row['direction'] ?? 'in') === 'in' && in_array($row['action'], ['allow', 'deny'], true);
    }

    private static function key(array $row): string
    {
        $source = $row['source'];
        if (self::incoming($row) && self::ports($row['to']) !== null && ! str_contains($source, ' on ')) {
            $address = self::scope($source);
            $source = ($address['six'] ? 'true' : 'false').':'.bin2hex($address['base']).'/'.$address['bits'];
        }

        return implode("\0", [$row['to'], $row['action'], $row['direction'] ?? 'in', $source]);
    }

    private static function v6(array $row): bool
    {
        return str_contains($row['to'], '(v6)') || str_contains($row['source'], '(v6)') || str_contains($row['source'], ':');
    }

    private static function managed(array $row): bool
    {
        return str_starts_with($row['comment'], 'orbit:');
    }

    /** @return array{0: int, 1: int, 2: ?string}|null */
    private static function ports(string $to): ?array
    {
        if (! preg_match('/^(\d{1,5})(?::(\d{1,5}))?(?:\/(tcp|udp))?(?: \(v6\))?$/', $to, $m)) {
            return null;
        }

        return [(int) $m[1], (int) (($m[2] ?? '') !== '' ? $m[2] : $m[1]), ($m[3] ?? '') !== '' ? $m[3] : null];
    }

    private static function scope(string $source): array
    {
        $text = preg_replace('/ \(v6\)$/', '', explode(' on ', $source)[0]);
        if ($text === 'Anywhere') {
            return self::network(str_ends_with($source, '(v6)') ? '::/0' : '0.0.0.0/0');
        }

        return self::network($text);
    }

    /** Prefer `row` below `other`: a managed line, or a broader overlapping operator line. */
    private static function below(array $row, array $other): bool
    {
        if (! self::incoming($row) || ! self::incoming($other) || self::v6($row) !== self::v6($other)) {
            return false;
        }
        $a = self::ports($row['to']);
        $b = self::ports($other['to']);
        if ($a && $b && ($a[1] < $b[0] || $b[1] < $a[0] || ($a[2] !== null && $b[2] !== null && $a[2] !== $b[2]))) {
            return false;
        }
        if (self::managed($row) || self::managed($other)) {
            return self::managed($row) && ! self::managed($other);
        }
        if (! $a || ! $b) {
            return false;
        }
        $wide = self::scope($row['source']);
        $narrow = self::scope($other['source']);

        return ! ($wide['base'] === $narrow['base'] && $wide['bits'] === $narrow['bits']) && self::contains($wide, $narrow);
    }

    /** @param array<string, true> $mine */
    private static function ordered(array $rows, array $mine): bool
    {
        foreach ($rows as $index => $row) {
            if (! isset($mine[self::key($row)])) {
                continue;
            }
            foreach ($rows as $position => $other) {
                if (! (($position < $index && self::below($other, $row)) || ($position > $index && self::below($row, $other)))) {
                    continue;
                }
                // Managed ALLOWs cannot change an existing ALLOW's verdict.
                if ($row['action'] === 'allow' && $other['action'] === 'allow' && (self::managed($row) || self::managed($other))) {
                    continue;
                }
                // Otherwise a placement preference requires a move only when the sources overlap.
                $a = self::scope($row['source']);
                $b = self::scope($other['source']);
                if (self::contains($a, $b) || self::contains($b, $a)) {
                    return false;
                }
            }
        }

        return true;
    }


    private static function has(array $rows, array $row): bool
    {
        $key = self::key($row);

        return array_any($rows, fn (array $item): bool => self::key($item) === $key);
    }

    private static function without(array $rows, array $row): array
    {
        $key = self::key($row);

        return array_values(array_filter($rows, fn (array $item): bool => self::key($item) !== $key));
    }

    private static function firstIndex(array $rows, callable $match): int
    {
        foreach ($rows as $i => $row) {
            if ($match($row)) {
                return $i;
            }
        }

        return -1;
    }
}
