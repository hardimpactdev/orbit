<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Release A: clear ADE/OpenCode/PolyScope intent while retaining physical
 * rollback-shadow columns for start-first gateway cutover.
 */
return new class extends Migration {
    /**
     * @var list<string>
     */
    private const array RemovedPermissions = [
        'agent-ide:*',
        'agent-ide:message',
        'instance:agent',
        'node:agent',
        'instance:prune',
        'app:agent',
        'app:prune',
    ];

    /**
     * @var list<string>
     */
    private const array RemovedToolNames = [
        'opencode-cli',
        'opencode',
        'opencode-server',
        'polyscope-server',
    ];

    /**
     * @var list<string>
     */
    private const array RemovedProcessNames = [
        'opencode-server',
        'polyscope-server',
    ];

    public function up(): void
    {
        DB::table('nodes')->update(['agent_ide_config' => null]);
        DB::table('apps')->update(['agent_ide_config' => null]);
        DB::table('app_instances')->update(['agent_ide_config' => null]);
        DB::table('workspaces')->update([
            'agent_ide' => null,
            'agent_ide_workspace_id' => null,
        ]);
        DB::table('processes')
            ->where('crash_notification', 'agent_ide')
            ->update(['crash_notification' => 'none']);

        $this->scrubPermissions('permissions');
        $this->scrubPermissions('custom_permissions');

        DB::table('node_tools')
            ->whereIn('name', self::RemovedToolNames)
            ->delete();

        DB::table('processes')
            ->where(function (Builder $query): void {
                $query->whereIn('name', self::RemovedProcessNames)
                    ->orWhereIn('tool', self::RemovedToolNames);
            })
            ->delete();
    }

    public function down(): void
    {
        // Cleared ADE configuration, permissions, tool rows, and process intent
        // cannot be reconstructed. Physical columns remain for rollback shadows.
    }

    private function scrubPermissions(string $column): void
    {
        $rows = DB::table('node_access')->select(['id', $column])->get();

        foreach ($rows as $row) {
            $raw = $row->{$column};
            $permissions = is_string($raw) ? json_decode($raw, true) : $raw;

            if (! is_array($permissions)) {
                continue;
            }

            $filtered = array_values(array_filter(
                $permissions,
                static fn (mixed $permission): bool => (
                    is_string($permission) && ! in_array($permission, self::RemovedPermissions, true)
                ),
            ));

            if ($filtered === $permissions) {
                continue;
            }

            DB::table('node_access')
                ->where('id', $row->id)
                ->update([$column => json_encode(array_values($filtered))]);
        }
    }
};
