<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\OrbitUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('update')]
#[Description('Update this Orbit checkout')]
class UpdateCommand extends Command
{
    public function handle(OrbitUpdater $updater): int
    {
        $result = $updater->updateLocal();

        if (! $result->successful()) {
            $this->error('Failed to update local Orbit checkout.');
            $this->line(trim($result->errorOutput() ?: $result->output()));

            return self::FAILURE;
        }

        $this->info('Updated local Orbit checkout.');

        return self::SUCCESS;
    }
}
