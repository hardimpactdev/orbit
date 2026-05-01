<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\OrbitUpdater;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('update:all')]
#[Description('Update this Orbit checkout and every active registered node')]
class UpdateAllCommand extends Command
{
    public function handle(OrbitUpdater $updater): int
    {
        $localResult = $updater->updateLocal();

        if (! $localResult->successful()) {
            $this->error('Failed to update local Orbit checkout.');
            $this->line(trim($localResult->errorOutput() ?: $localResult->output()));

            return self::FAILURE;
        }

        $this->info('Updated local Orbit checkout.');

        $failed = false;

        Node::query()
            ->where('status', 'active')
            ->where('is_local', false)
            ->orderBy('name')
            ->get()
            ->each(function (Node $node) use ($updater, &$failed): void {
                $result = $updater->updateRemote($node);

                if (! $result->successful()) {
                    $failed = true;
                    $this->error("Failed to update node {$node->name}.");
                    $this->line(trim($result->errorOutput() ?: $result->output()));

                    return;
                }

                $this->info("Updated node {$node->name}.");
            });

        if ($failed) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
