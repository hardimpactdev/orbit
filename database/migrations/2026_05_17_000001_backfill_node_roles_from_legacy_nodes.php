<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();

        $assignments = DB::table('nodes')
            ->select(['id', 'role', 'environment', 'tld'])
            ->get()
            ->map(function (object $node) use ($timestamp): ?array {
                if ($node->role === 'gateway') {
                    return [
                        'node_id' => $node->id,
                        'role' => 'gateway',
                        'status' => 'active',
                        'settings' => json_encode([], JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($node->role === 'app' && $node->environment === 'development') {
                    return [
                        'node_id' => $node->id,
                        'role' => 'app-development',
                        'status' => 'active',
                        'settings' => json_encode(['tld' => $node->tld], JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($node->role === 'app' && $node->environment === 'production') {
                    return [
                        'node_id' => $node->id,
                        'role' => 'app-production',
                        'status' => 'active',
                        'settings' => json_encode([], JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();

        if ($assignments === []) {
            return;
        }

        DB::table('node_roles')->insertOrIgnore($assignments);
    }

    public function down(): void {}
};
