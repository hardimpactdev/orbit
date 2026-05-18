<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Models\Node;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class DnsmasqReconciler
{
    public function __construct(
        private readonly DnsmasqConfigBuilder $configBuilder,
        private readonly string $rootPath,
    ) {}

    public function reconcile(): void
    {
        File::ensureDirectoryExists($this->rootPath);

        $confPath = $this->rootPath.'/dnsmasq.conf';
        $expected = $this->configBuilder->build(Node::query()->get());
        $current = File::exists($confPath) ? File::get($confPath) : null;

        if ($current === $expected) {
            return;
        }

        File::put($confPath, $expected);

        Process::timeout(30)->run('docker restart orbit-dns');
    }
}
