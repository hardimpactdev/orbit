<?php

declare(strict_types=1);

namespace App\Domain\AppDev;

use InvalidArgumentException;

/**
 * The complete private DNS of the vpn Node, as data. One Ansible phase writes it as dnsmasq files.
 *
 * `tlds` answer every name below them. A record below a TLD is listed only when its answer differs from
 * its TLD; it needs no NODATA pair. A record outside every TLD, such as a Route on its own domain or a
 * reserved name, carries `pair`: the phase gives it local NODATA for other types and forwards its children. A name under a local
 * zone (`.orbit`) keeps `pair` in the data, but the phase renders no forward rule for it: dnsmasq answers its children NXDOMAIN itself.
 */
final readonly class PrivateDnsState
{
    private const string Name = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/';

    /**
     * @param  list<string>  $listen
     * @param  array<string, string>  $tlds  TLD => address
     * @param  array<string, string>  $records  name => address
     */
    private function __construct(
        public array $listen,
        public array $tlds,
        public array $records,
    ) {}

    /**
     * @param  list<string>  $listen
     * @param  array<string, string>  $tlds  TLD => address
     * @param  array<string, string>  $hosts  name => address
     */
    public static function from(array $listen, array $tlds, array $hosts): self
    {
        foreach ($listen as $address) {
            self::address($address);
        }

        foreach ($tlds as $tld => $address) {
            self::name((string) $tld);
            self::address($address);
        }

        $records = [];

        foreach ($hosts as $name => $address) {
            self::name((string) $name);
            self::address($address);
            $records[$name] = $address;
        }

        ksort($records);
        ksort($tlds);

        return new self($listen, $tlds, $records);
    }

    /** The most specific TLD that names the record, if any. */
    public function tldOf(string $name): ?string
    {
        $found = null;

        foreach (array_keys($this->tlds) as $tld) {
            $tld = (string) $tld;

            if (($name === $tld || str_ends_with($name, '.'.$tld)) && ($found === null || strlen($tld) > strlen($found))) {
                $found = $tld;
            }
        }

        return $found;
    }

    /**
     * What the phase takes: records the TLD wildcard already answers are dropped.
     *
     * @return array{listen: list<string>, tlds: list<array{name: string, address: string}>, records: list<array{name: string, address: string, pair: bool}>}
     */
    public function toIntent(): array
    {
        $records = [];

        foreach ($this->records as $name => $address) {
            $tld = $this->tldOf((string) $name);

            if ($tld !== null && $this->tlds[$tld] === $address) {
                continue;
            }

            $records[] = ['name' => (string) $name, 'address' => $address, 'pair' => $tld === null];
        }

        $tlds = [];

        foreach ($this->tlds as $name => $address) {
            $tlds[] = ['name' => (string) $name, 'address' => $address];
        }

        return ['listen' => $this->listen, 'tlds' => $tlds, 'records' => $records];
    }

    private static function name(string $name): void
    {
        if (preg_match(self::Name, $name) !== 1) {
            throw new InvalidArgumentException("Private DNS name [{$name}] is not a lowercase DNS name.");
        }
    }

    private static function address(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException("Private DNS address [{$address}] is not an IPv4 address.");
        }
    }
}
