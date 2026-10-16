<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['instance_deploy_steps', 'project_development_deploy_steps', 'project_lifecycle_steps'] as $table) {
            DB::table($table)->orderBy('id')->eachById(function (object $step) use ($table): void {
                if (! in_array($step->name, ['npm-build', 'npm-install'], true)) {
                    return;
                }
                $command = $step->command;
                $legacy = preg_match('~\A(?:node|/(?:[A-Za-z0-9._-]+/)*node)[ \t]+/(?:[A-Za-z0-9._-]+/)*npm-cli\.js[ \t]+(run[ \t]+build|ci|install)\z~D', $command, $match) === 1;
                $plain = in_array($command, ['npm run build', 'npm ci', 'npm install'], true);
                if ($legacy || $plain) {
                    $build = $legacy ? str_starts_with($match[1], 'run') : $command === 'npm run build';
                    DB::table($table)->where('id', $step->id)->update(['command' => $build ? 'vp run build' : 'vp install --frozen-lockfile']);
                }
            });
        }
    }

    public function down(): void
    {
        // The old host-specific paths are neither portable nor recoverable. Keep portable commands on rollback.
    }
};
