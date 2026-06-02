<?php

declare(strict_types=1);

namespace App\Commands\Dns;

use App\Commands\LocalOnlyCommand;
use App\Services\Dns\ResolvesLocalDns;
use RuntimeException;

use function Laravel\Prompts\table;

final class DnsListCommand extends LocalOnlyCommand
{
    #[\Override]
    protected $signature = 'dns:list {--json : Output as JSON}';

    #[\Override]
    protected $description = 'List caller-local Orbit DNS resolver overrides';

    public function handle(ResolvesLocalDns $resolver): int
    {
        if (! $resolver->isSupported()) {
            return $this->renderFailure(
                'node.unsupported_platform',
                'This platform does not support automatic local DNS resolver inspection.',
                ['platform' => $resolver->platform()],
            );
        }

        try {
            $dns = $resolver->listOverrides();
        } catch (RuntimeException) {
            return $this->renderFailure(
                'local_resolver_read_failed',
                'Could not read local DNS resolver configuration.',
                ['resolver_backend' => $resolver->backend()],
            );
        }

        $meta = [
            'count' => count($dns),
            'resolver_backend' => $resolver->backend(),
        ];

        if ($this->wantsJson()) {
            return $this->renderSuccess(['dns' => $dns], $meta);
        }

        $this->renderHuman($dns);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, string>>  $dns
     */
    private function renderHuman(array $dns): void
    {
        if ($dns === []) {
            $this->line('No local Orbit DNS overrides found.');

            return;
        }

        table(
            headers: ['TLD', 'TARGET', 'SOURCE', 'BACKEND', 'STATUS'],
            rows: array_map(fn (array $entry): array => [
                $entry['tld'],
                $entry['target'],
                $entry['source'],
                $entry['resolver_backend'],
                $entry['status'],
            ], $dns),
        );
    }
}
