<?php

declare(strict_types=1);

namespace App\Domain\Firewall;

use UnexpectedValueException;

/**
 * Compares the `ufw` fact a Node's agent reported with the rules Orbit intends there. Orbit's own rules carry the
 * comment `orbit` or `orbit <label>` and match by tuple first, then by label to detect drift. Other rules match by comment.
 * IPv6 rules are left out, as Orbit intends only IPv4 rules.
 *
 * @phpstan-type Shape array{comment: string, action: string, direction: string, source: string, destination: string, port: string, protocol: string, in_interface: ?string, out_interface: ?string, family: ?string}
 * @phpstan-type Row array{name: string, comment: string, action: string, source: string, destination: string, port: string, protocol: string, interface: ?string, family: string, match: string}
 */
final readonly class LiveFirewallSnapshot
{
    public const int MaxRules = 512;

    /**
     * @param  array<string, mixed>  $ufw  the `ufw` fact data
     * @param  list<array{name: string, shape: array<string, mixed>}>  $intended
     * @return array{backend: string, live: list<Row>, missing: list<Row>}
     *
     * @throws UnexpectedValueException when a rule cannot be read
     */
    public static function compare(array $ufw, array $intended): array
    {
        if (($ufw['installed'] ?? false) !== true) {
            throw new UnexpectedValueException('UFW is not installed.');
        }
        $backend = ($ufw['enabled'] ?? false) === true ? 'active' : 'inactive';
        $live = [];
        // A disabled UFW applies none of its rules, so none is live.
        foreach ($backend === 'active' && is_array($ufw['rules'] ?? null) ? $ufw['rules'] : [] as $rule) {
            $shape = is_array($rule) ? self::shape($rule) : throw new UnexpectedValueException('A UFW rule is unreadable.');
            if ($shape !== null) {
                $live[] = $shape;
            }
        }
        if (count($live) > self::MaxRules) {
            throw new UnexpectedValueException('UFW has too many rules to compare.');
        }
        $expected = [];
        foreach ($intended as $rule) {
            $shape = self::intended($rule['shape']);
            if ($shape['family'] !== 'v6') {
                $expected[] = ['name' => $rule['name'], 'shape' => $shape];
            }
        }

        $classified = array_map(static function (array $rule) use ($expected): array {
            $owners = array_values(array_filter($expected, static fn (array $e): bool => self::sameLabel($rule, $e['shape'])));
            $tuples = array_values(array_filter($expected, static fn (array $e): bool => self::named($e['shape']) && self::exact($rule, $e['shape'])));
            $candidates = self::named($rule) ? ($tuples !== [] ? $tuples : $owners) : ($owners !== [] ? $owners : $tuples);

            return self::row($rule, $expected, match (true) {
                $candidates === [] => 'unmanaged',
                array_any($candidates, static fn (array $e): bool => self::exact($rule, $e['shape'])) => 'exact',
                default => 'drift',
            });
        }, $live);
        $missing = $backend !== 'active' ? [] : array_values(array_map(
            static fn (array $e): array => self::row([...$e['shape'], 'family' => 'v4'], $expected, 'missing'),
            array_filter($expected, static function (array $e) use ($live, $classified): bool {
                if (! self::named($e['shape'])) {
                    return ! array_any($live, static fn (array $rule): bool => $rule['comment'] === $e['shape']['comment']);
                }
                if (array_any($classified, static fn (array $row): bool => $row['match'] === 'drift' && $row['comment'] === $e['shape']['comment'])
                    || array_any($live, static fn (array $rule): bool => self::matches($rule, $e['shape']))) {
                    return false;
                }
                if ($e['shape']['port'] === 'any') {
                    return true;
                }
                [$start, $end] = self::bounds($e['shape']['port']);

                return array_any(range($start, $end), static fn (int $port): bool => ! array_any($live, static fn (array $rule): bool => self::matches($rule, $e['shape'], (string) $port)));
            }),
        ));

        return ['backend' => $backend, 'live' => $classified, 'missing' => $missing];
    }

    /**
     * An intended rule as a shape.
     *
     * @param  array<string, mixed>  $shape
     * @return Shape
     */
    private static function intended(array $shape): array
    {
        $text = static fn (string $key): string => is_string($shape[$key] ?? null) ? $shape[$key] : '';
        $optional = static fn (string $key): ?string => is_string($shape[$key] ?? null) ? $shape[$key] : null;

        return ['comment' => $text('comment'), 'action' => $text('action'), 'direction' => $text('direction'), 'source' => $text('source'),
            'destination' => $text('destination'), 'port' => $text('port'), 'protocol' => $text('protocol'),
            'in_interface' => $optional('in_interface'), 'out_interface' => $optional('out_interface'), 'family' => $optional('family')];
    }

    /**
     * One fact rule as a shape, or null for an IPv6 rule.
     *
     * @param  array<mixed>  $rule
     * @return Shape|null
     */
    private static function shape(array $rule): ?array
    {
        if (($rule['unreadable'] ?? false) === true || ($rule['app'] ?? false) === true) {
            throw new UnexpectedValueException('A UFW rule is unreadable or names an application profile.');
        }
        if (($rule['family'] ?? null) === 'v6') {
            return null;
        }
        $action = $rule['action'] ?? null;
        $direction = $rule['direction'] ?? null;
        $protocol = $rule['protocol'] ?? null;
        $port = $rule['port'] ?? null;
        if (! in_array($action, ['allow', 'deny'], true) || ! in_array($direction, ['in', 'out', 'fwd'], true)
            || ! in_array($protocol, ['any', 'tcp', 'udp'], true) || ! is_string($port)) {
            throw new UnexpectedValueException('A UFW rule has an action, direction, protocol or port Orbit does not compare.');
        }
        if ($port !== 'any') {
            if (preg_match('/\A(\d{1,5})(?::(\d{1,5}))?\z/', $port, $m) !== 1) {
                throw new UnexpectedValueException('A UFW rule has a port Orbit does not compare.');
            }
            $start = (int) $m[1];
            $end = isset($m[2]) ? (int) $m[2] : $start;
            if ($start < 1 || $end > 65535 || $start > $end) {
                throw new UnexpectedValueException('A UFW rule has an invalid port.');
            }
        }
        $inward = is_string($rule['in_interface'] ?? null) ? $rule['in_interface'] : null;
        $outward = is_string($rule['out_interface'] ?? null) ? $rule['out_interface'] : null;

        return [
            'comment' => is_string($rule['comment'] ?? null) ? trim($rule['comment']) : '',
            'action' => $action,
            'direction' => $direction,
            'source' => self::endpoint($rule['source'] ?? null),
            'destination' => self::endpoint($rule['destination'] ?? null),
            'port' => $port,
            'protocol' => $protocol,
            'in_interface' => $direction === 'out' ? null : $inward,
            'out_interface' => $direction === 'in' ? null : $outward,
            'family' => 'v4',
        ];
    }

    private static function endpoint(mixed $value): string
    {
        if (in_array($value, ['any', '0.0.0.0/0'], true)) {
            return 'any';
        }
        $parts = is_string($value) ? explode('/', $value) : [];
        if ($parts === [] || count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new UnexpectedValueException('A UFW rule has an address Orbit does not compare.');
        }
        if (! isset($parts[1]) || $parts[1] === '32') {
            return $parts[0];
        }
        if (preg_match('/\A\d{1,2}\z/', $parts[1]) !== 1 || (int) $parts[1] > 32) {
            throw new UnexpectedValueException('A UFW rule has an invalid prefix.');
        }
        $bits = (int) $parts[1];
        if ($bits === 0) {
            return 'any';
        }
        $network = ip2long($parts[0]) & (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return long2ip($network).'/'.$bits;
    }

    /** @param Shape $shape */
    private static function named(array $shape): bool
    {
        return preg_match('/\Aorbit( |\z)/', $shape['comment']) === 1;
    }

    /**
     * The bare `orbit` comment is shared by unlabelled rules, so it cannot identify a rule with drift.
     *
     * @param  Shape  $actual
     * @param  Shape  $intended
     */
    private static function sameLabel(array $actual, array $intended): bool
    {
        return $intended['comment'] !== 'orbit' && $actual['comment'] === $intended['comment'];
    }

    /** @return array{int, int} */
    private static function bounds(string $port): array
    {
        $parts = array_map(intval(...), explode(':', $port));

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    /**
     * @param  Shape  $actual
     * @param  Shape  $intended
     */
    private static function matches(array $actual, array $intended, ?string $port = null): bool
    {
        foreach ([...$intended, 'port' => $port ?? $intended['port']] as $key => $value) {
            if (($key === 'family' && $value === null) || ($key === 'comment' && self::named($intended))) {
                continue;
            }
            if ($actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * An Orbit rule over a port range also matches one live rule per port.
     *
     * @param  Shape  $actual
     * @param  Shape  $intended
     */
    private static function exact(array $actual, array $intended): bool
    {
        if (self::matches($actual, $intended)) {
            return true;
        }
        if (! self::named($intended) || $intended['port'] === 'any' || $actual['port'] === 'any' || str_contains($actual['port'], ':')) {
            return false;
        }
        [$start, $end] = self::bounds($intended['port']);
        $port = (int) $actual['port'];

        return $port >= $start && $port <= $end && self::matches($actual, $intended, $actual['port']);
    }

    /**
     * @param  Shape  $shape
     * @param  list<array{name: string, shape: Shape}>  $expected
     * @return Row
     */
    private static function row(array $shape, array $expected, string $match): array
    {
        $name = null;
        foreach ($expected as $e) {
            if ($e['shape']['comment'] === $shape['comment']) {
                $name = $e['name'];
                break;
            }
        }
        foreach ($name === null ? $expected : [] as $e) {
            if (self::named($e['shape']) && self::exact($shape, $e['shape'])) {
                $name = $e['name'];
                break;
            }
        }

        return [
            'name' => $name ?? ($shape['comment'] !== '' ? $shape['comment'] : 'unmanaged'),
            'comment' => $shape['comment'], 'action' => $shape['action'], 'source' => $shape['source'],
            'destination' => $shape['destination'], 'port' => $shape['port'], 'protocol' => $shape['protocol'],
            'interface' => $shape['in_interface'], 'family' => 'v4', 'match' => $match,
        ];
    }
}
