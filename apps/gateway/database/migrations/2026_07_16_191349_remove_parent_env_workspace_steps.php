<?php

declare(strict_types=1);

use App\Services\Workspaces\WorkspaceEnvInheritanceGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $unsafeIds = [];
        $guard = new WorkspaceEnvInheritanceGuard;

        foreach (DB::table('workspace_steps')->select(['id', 'command'])->orderBy('id')->get() as $step) {
            $row = (array) $step;

            if (
                ! isset($row['id'], $row['command'])
                || ! is_int($row['id'])
                || ! is_string($row['command'])
                || ! $guard->consumesParentEnv($row['command'])
            ) {
                continue;
            }

            $unsafeIds[] = $row['id'];
        }

        if ($unsafeIds !== []) {
            DB::table('workspace_steps')->whereIn('id', $unsafeIds)->delete();
        }
    }

    public function down(): void {}
};
