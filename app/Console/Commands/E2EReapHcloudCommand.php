<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\E2E\HcloudE2EReaper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('e2e:reap-hcloud
    {--force : Delete stale resources instead of reporting a dry run}
    {--older-than=60 : Only include resources older than this many minutes}
    {--snapshots : Include hcloud snapshots labelled orbit-e2e=true}
    {--json : Output as JSON}')]
#[Description('Reap stale hcloud resources created by ephemeral E2E tests')]
class E2EReapHcloudCommand extends Command
{
    protected $hidden = true;

    public function handle(HcloudE2EReaper $reaper): int
    {
        $olderThan = (int) $this->option('older-than');

        if ($olderThan < 0) {
            return $this->failValidation('older-than must be zero or greater.');
        }

        try {
            $result = $reaper->reap(
                olderThanMinutes: $olderThan,
                force: (bool) $this->option('force'),
                includeSnapshots: (bool) $this->option('snapshots'),
            );
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['success' => ['data' => $result]], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->renderHuman($result);

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *     provider: string,
     *     dry_run: bool,
     *     older_than_minutes: int,
     *     include_snapshots: bool,
     *     resources: list<array{type: string, id: string, name: string, created: string, deleted: bool}>
     * }  $result
     */
    private function renderHuman(array $result): void
    {
        if ($result['dry_run']) {
            $this->line('Dry run. Pass --force to delete stale resources.');
        }

        if ($result['resources'] === []) {
            $this->line('No stale hcloud E2E resources found.');

            return;
        }

        foreach ($result['resources'] as $resource) {
            $status = $resource['deleted'] ? 'deleted' : 'stale';

            $this->line("{$status}: {$resource['type']} {$resource['id']} {$resource['name']} {$resource['created']}");
        }

        if (! $result['dry_run']) {
            $this->line('Deleted '.count($result['resources']).' stale hcloud E2E resources.');
        }
    }

    private function failValidation(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'hcloud_e2e_reap_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
