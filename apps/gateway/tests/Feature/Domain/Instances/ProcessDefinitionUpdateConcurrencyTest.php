<?php

declare(strict_types=1);

use App\Actions\ProjectDefinitions\ReplaceProcessDefinitionAction;
use App\Data\ProjectDefinitions\ProjectDefinitionInputData;
use App\Models\ProcessDefinition;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ContainerInstanceHelpers.php';

it('commits both concurrent sparse edits made from the same stored definition', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'orbit-gateway-test-definition-');
    $this->beforeApplicationDestroyed(static function () use ($file): void {
        DB::purge();
        RefreshDatabaseState::$migrated = false;
        foreach ([$file, $file.'-wal', $file.'-shm', $file.'.0', $file.'.1'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    });
    DB::purge();
    config(['database.connections.sqlite.database' => $file]);
    expect(Artisan::call('migrate:fresh', ['--force' => true]))->toBe(0);
    $project = containerDefaultProject();
    $original = ['runtime' => 'systemd', 'command' => ['/usr/bin/worker', '--api-token=synthetic-secret'],
        'environment' => ['TOKEN' => 'synthetic-environment-secret'], 'keep_alive' => false, 'restart_policy' => 'never'];
    $definition = ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'worker',
        'environments' => ['development'], 'spec' => $original]);
    $id = $definition->id;
    $tasks = [];
    foreach ([['keep_alive' => true], ['restart_policy' => 'always']] as $index => $change) {
        $tasks[] = Closure::bind(static function () use ($file, $id, $index, $change): bool {
            config(['database.connections.sqlite.database' => $file]);
            DB::purge();
            $stale = ProcessDefinition::query()->findOrFail($id);
            touch($file.'.'.$index);
            $deadline = microtime(true) + 2;
            while (! is_file($file.'.'.(1 - $index))) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The other sparse edit did not reach the barrier.');
                }
                usleep(1000);
            }
            app(ReplaceProcessDefinitionAction::class)->execute(
                $stale, new ProjectDefinitionInputData('worker', ['development'], $change),
            );

            return true;
        }, null, null);
    }

    expect(Concurrency::driver('process')->run($tasks, timeout: 5))->toBe([true, true]);
    expect($definition->refresh()->spec)->toBe([...$original, 'keep_alive' => true, 'restart_policy' => 'always']);
});
