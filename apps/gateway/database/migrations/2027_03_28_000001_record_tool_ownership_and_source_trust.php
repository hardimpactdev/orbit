<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records the evidence behind uninstall authority and apt source trust, conservatively, from what the previous release stored.
 * docs/reference/tools.md#remove-a-tool and #source-trust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tools', static function (Blueprint $table): void {
            $table->boolean('owned')->default(false);
        });
        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->boolean('verified')->default(true);
        });
        // Orbit installed, adopted or converged an installed Tool, and a failed update or removal followed one. A failed first install
        // proves nothing: it may have observed a package Orbit never wrote.
        DB::table('tools')->where(static fn ($query) => $query->where('status', 'installed')
            ->orWhere(static fn ($failed) => $failed->where('status', 'failed')->whereIn('failed_operation', ['update', 'remove'])))
            ->update(['owned' => true]);

        $this->restoreCleanupHandles();
        foreach (DB::table('apt_repositories')->get(['id', 'node_id', 'name']) as $source) {
            $failed = DB::table('tools')->where('node_id', $source->node_id)->whereIn('status', ['failed', 'installing'])
                ->where(static fn ($query) => $query->where('apt_repository_id', $source->id)->orWhere('desired_repository->name', $source->name))
                ->exists();
            $latest = DB::table('operations')->where('node_id', $source->node_id)->whereIn('kind', ['tool.install', 'tool.update', 'tool.remove'])
                ->where('input->repository->name', $source->name)
                ->where(static fn ($query) => $query->where('input->repository_given', true)->orWhere('input->remove_repository', true))
                ->orderByDesc('id')->first(['kind', 'status', 'result']);
            $result = is_string($latest?->result) ? json_decode($latest->result, true) : null;
            // A best-effort source removal that reported success without confirming the source's removal leaves the files unknown.
            $unconfirmed = $latest !== null && ($latest->status !== 'succeeded'
                || ($latest->kind === 'tool.remove' && (is_array($result) ? $result['repository_removed'] ?? null : null) !== true));
            if ($failed || $unconfirmed) {
                DB::table('apt_repositories')->where('id', $source->id)->update(['verified' => false]);
            }
        }
    }

    /**
     * The previous release dropped a refused or cancelled first install whose run may already have written its apt source or pins. Its
     * Tool comes back as failed and unowned, so removing it cleans that source without touching the package.
     */
    private function restoreCleanupHandles(): void
    {
        $runs = DB::table('operations')->where('kind', 'tool.install')->whereIn('status', ['failed', 'cancelled'])
            ->whereNotNull('input->repository->name')->whereNotNull('input->tool_id')->orderBy('id')->get(['node_id', 'status', 'error_code', 'attempts', 'input']);
        foreach ($runs as $run) {
            $input = json_decode((string) $run->input, true);
            $ran = $run->status === 'failed' ? $run->error_code === 'tool.package_already_installed' : $run->attempts > 0;
            $source = is_array($input) && is_array($input['repository'] ?? null) ? $input['repository'] : null;
            if ($source === null || ! $ran || ($input['manager'] ?? null) !== 'apt' || DB::table('tools')->where('id', $input['tool_id'])->exists()) {
                continue;
            }
            $manager = DB::table('tool_managers')->where('node_id', $run->node_id)->where('name', 'apt')->value('id');
            $taken = DB::table('tools')->where('node_id', $run->node_id)->where(static fn ($query) => $query->where('package', $input['package'])
                ->orWhere('desired_repository->name', $source['name'] ?? null))->exists();
            if ($manager === null || $taken) {
                continue;
            }
            DB::table('tools')->insert(['node_id' => $run->node_id, 'tool_manager_id' => $manager, 'package' => $input['package'],
                'desired_version' => $input['desired_version'] ?? null, 'version_constraint' => $input['version_constraint'] ?? null,
                'desired_repository' => json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'status' => 'failed',
                'failed_operation' => 'install', 'error_code' => $run->error_code ?? 'tool.install_failed', 'owned' => false,
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('apt_repositories', static function (Blueprint $table): void {
            $table->dropColumn('verified');
        });
        Schema::table('tools', static function (Blueprint $table): void {
            $table->dropColumn('owned');
        });
    }
};
