<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $unsafeIds = [];

        foreach (DB::table('workspace_steps')->select(['id', 'command'])->orderBy('id')->get() as $step) {
            $row = (array) $step;

            if (
                ! isset($row['id'], $row['command'])
                || ! is_int($row['id'])
                || ! is_string($row['command'])
                || ! $this->consumesParentEnv($row['command'])
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

    private function consumesParentEnv(string $command): bool
    {
        return (
            preg_match(
                '/(?:\\$ORBIT_APP_PATH|\\$\\{ORBIT_APP_PATH\\})\\/\\.env(?![A-Za-z0-9_.-])/',
                $command,
            ) === 1
        );
    }
};
