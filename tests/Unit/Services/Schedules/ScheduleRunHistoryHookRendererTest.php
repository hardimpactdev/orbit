<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Schedules\ScheduleRunHistoryHookRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ScheduleRunHistoryHookRenderer', function (): void {
    it('renders deterministic hook path and install script from schedule intent', function (): void {
        $node = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);
        $schedule = Schedule::factory()->forApp($app)->create([
            'schedule_key' => 'app:docs:laravel-scheduler',
            'execution_value' => 'php artisan schedule:run',
        ]);
        $renderer = new ScheduleRunHistoryHookRenderer;

        $path = $renderer->path($schedule);
        $script = $renderer->installScript($schedule);
        $content = base64_decode((string) str($script)->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($path)->toBe('/opt/orbit/schedules/hooks/'.hash('sha256', 'app:docs:laravel-scheduler').'.sh')
            ->and($renderer->hash($schedule))->toHaveLength(64)
            ->and($script)->toContain("sudo install -d -m 0755 '/opt/orbit/schedules/hooks'")
            ->and($script)->toContain('sudo chmod 0755')
            ->and($content)->toContain('schedule_key=app:docs:laravel-scheduler')
            ->and($content)->toContain("cd '/home/orbit/apps/docs'")
            ->and($content)->toContain("exec /bin/bash -lc 'php artisan schedule:run'");
    });
});
