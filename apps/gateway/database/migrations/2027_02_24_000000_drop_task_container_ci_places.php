<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The task container side has no CI places any more: a CI Node's `ci.places` replace them (ADR 0260,
 * docs/reference/node-settings.md#ci-places). The `ci_places` key goes from every stored `task_vms.containers`, and every
 * other key stays. A run that finds no such key changes nothing, so it can run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('nodes')->whereNotNull('settings')->get(['id', 'settings']) as $row) {
            $settings = json_decode((string) $row->settings, true);
            $host = is_array($settings) ? $settings['task_vms'] ?? null : null;
            $containers = is_array($host) ? $host['containers'] ?? null : null;
            if (! is_array($settings) || ! is_array($host) || ! is_array($containers) || ! array_key_exists('ci_places', $containers)) {
                continue;
            }
            unset($containers['ci_places']);
            $host['containers'] = $containers;
            $settings['task_vms'] = $host;
            DB::table('nodes')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
        }
    }
};
