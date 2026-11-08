<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Replace stored host capacity settings with the approved separate pools. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('nodes')->whereNotNull('settings')->get(['id', 'name', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);
            if (! is_array($settings) || ! is_array($settings['task_vms'] ?? null)) {
                continue;
            }
            [$task, $ci] = match (strtolower($row->name)) {
                'beast', 'shark' => [2, 2],
                'sabre' => [0, 2],
                default => [0, 0],
            };
            $settings['task_vms']['task_places'] ??= $task;
            $settings['task_vms']['ci_places'] ??= $ci;
            unset($settings['task_vms']['max_vms'], $settings['task_vms']['task_max_vms'], $settings['task_vms']['ci_only']);
            DB::table('nodes')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
    }
};
