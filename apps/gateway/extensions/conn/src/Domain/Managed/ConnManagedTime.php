<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Managed;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A target-reported RFC 3339 time with an explicit offset ([Timestamps](/reference/conn#timestamps)). It refuses any
 * calendar or clock value that does not exist and keeps every fractional digit, so two times compare exactly.
 */
final readonly class ConnManagedTime
{
    private const string Pattern = '/^(?<y>\d{4})-(?<mo>\d{2})-(?<d>\d{2})T(?<h>\d{2}):(?<mi>\d{2}):(?<s>\d{2})(?:\.(?<f>\d{1,9}))?(?:Z|(?<sign>[+-])(?<oh>\d{2}):(?<om>\d{2}))$/';

    private function __construct(public int $epochSeconds, public int $nanoseconds) {}

    public static function parse(mixed $value): ?self
    {
        if (! is_string($value) || preg_match(self::Pattern, $value, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }
        $part = static fn (string $name): int => (int) ($m[$name] ?? 0);
        if (! checkdate($part('mo'), $part('d'), $part('y')) || $part('h') > 23 || $part('mi') > 59 || $part('s') > 59 || $part('oh') > 23 || $part('om') > 59) {
            return null;
        }
        $offset = ($m['sign'] ?? null) === null ? '+00:00' : sprintf('%s%02d:%02d', $m['sign'], $part('oh'), $part('om'));
        $moment = new DateTimeImmutable(
            sprintf('%04d-%02d-%02dT%02d:%02d:%02d', $part('y'), $part('mo'), $part('d'), $part('h'), $part('mi'), $part('s')),
            new DateTimeZone($offset),
        );

        return new self($moment->getTimestamp(), (int) str_pad((string) ($m['f'] ?? ''), 9, '0'));
    }

    public function before(self $other): bool
    {
        return $this->epochSeconds < $other->epochSeconds
            || ($this->epochSeconds === $other->epochSeconds && $this->nanoseconds < $other->nanoseconds);
    }
}
