<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->boolean('verified')->default(true);
        });
        // A recorded source stays verified unless a failed Tool shares it or its latest direct write did not succeed.
        foreach (DB::table('apt_repositories')->get(['id', 'node_id', 'name']) as $source) {
            $failed = DB::table('tools')->where('node_id', $source->node_id)->where('status', 'failed')
                ->where(static fn ($query) => $query->where('apt_repository_id', $source->id)->orWhere('desired_repository->name', $source->name))
                ->exists();
            $latest = DB::table('operations')->where('node_id', $source->node_id)->whereIn('kind', ['tool.install', 'tool.update', 'tool.remove'])
                ->where('input->repository->name', $source->name)
                ->where(static fn ($query) => $query->where('input->repository_given', true)->orWhere('input->remove_repository', true))
                ->orderByDesc('id')->value('status');
            if ($failed || ($latest !== null && $latest !== 'succeeded')) {
                DB::table('apt_repositories')->where('id', $source->id)->update(['verified' => false]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->dropColumn('verified');
        });
    }
};
