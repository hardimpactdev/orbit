<?php

declare(strict_types=1);

namespace App\Services\Dns;

final readonly class DnsmasqBaseConfigBuilder
{
    public function build(): string
    {
        return implode("\n", [
            '# orbit-managed=dnsmasq-base',
            'no-resolv',
            'server=1.1.1.1',
            'server=8.8.8.8',
            'conf-file=/etc/dnsmasq.d/10-node-records.conf',
            'conf-file=/etc/dnsmasq.d/20-proxy-records.conf',
            'log-queries',
            'log-facility=-',
            '',
        ]);
    }
}
