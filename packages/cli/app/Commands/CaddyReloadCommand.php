<?php

declare(strict_types=1);

namespace App\Commands;

use App\Concerns\WithJsonOutput;
use App\Contracts\CaddyfileGeneratorInterface;
use App\Services\CaddyManager;
use LaravelZero\Framework\Commands\Command;

final class CaddyReloadCommand extends Command
{
    use WithJsonOutput;

    protected $signature = 'caddy:reload {--json : Output as JSON}';

    protected $description = 'Regenerate Caddyfile and reload Caddy';

    public function handle(
        CaddyfileGeneratorInterface $caddyfileGenerator,
        CaddyManager $caddyManager
    ): int {
        // Regenerate Caddyfile
        $caddyfileGenerator->generate();

        // Reload Caddy
        $reloaded = $caddyManager->reload();

        if ($this->wantsJson()) {
            return $this->outputJson([
                'success' => $reloaded,
                'data' => [
                    'action' => 'caddy:reload',
                    'reloaded' => $reloaded,
                ],
            ], $reloaded ? self::SUCCESS : self::FAILURE);
        }

        if ($reloaded) {
            $this->info('Caddyfile regenerated and Caddy reloaded.');
        } else {
            $this->error('Failed to reload Caddy.');
        }

        return $reloaded ? self::SUCCESS : self::FAILURE;
    }
}
