<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * @var array<string, string>
     */
    private const array PermissionRewrites = [
        'process:logs' => 'process:log',
        'workspace:log' => 'workspace:run:log',
    ];

    public function up(): void
    {
        $grants = DB::table('node_access')->orderBy('id')->get(['id', 'permissions', 'custom_permissions']);

        foreach ($grants as $grant) {
            $permissions = $this->rewriteList($this->decodePermissions($grant->permissions));
            $custom = $this->rewriteList($this->decodePermissions($grant->custom_permissions));

            DB::table('node_access')
                ->where('id', $grant->id)
                ->update([
                    'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
                    'custom_permissions' => json_encode($custom, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function rewriteList(array $permissions): array
    {
        /** @var list<string> $rewritten */
        $rewritten = [];

        foreach ($permissions as $permission) {
            $token = self::PermissionRewrites[$permission] ?? $permission;

            if (! in_array($token, $rewritten, true)) {
                $rewritten[] = $token;
            }
        }

        return $rewritten;
    }

    /**
     * @return list<string>
     */
    private function decodePermissions(mixed $value): array
    {
        if (! is_string($value)) {
            throw new UnexpectedValueException('Stored node permissions must be JSON strings.');
        }

        $decoded = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new UnexpectedValueException('Stored node permissions must be JSON lists.');
        }

        if (array_filter($decoded, static fn (mixed $permission): bool => ! is_string($permission)) !== []) {
            throw new UnexpectedValueException('Stored node permissions must contain only strings.');
        }

        /** @var list<string> $decoded */
        return $decoded;
    }
};
