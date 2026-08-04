<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist a durable human display label beside the stable process identity slug.
 * Existing rows backfill label = name (identity key).
 *
 * Avoid Schema column change() on SQLite: it rebuilds the table and can drop
 * partial unique indexes such as per-instance process name uniqueness.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('processes', static function (Blueprint $table): void {
            // Non-null with temporary empty default so existing rows can be
            // backfilled without a destructive table rebuild on SQLite.
            $table->string('label')->default('')->after('name');
        });

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            DB::statement(
                'UPDATE processes SET label = name WHERE label = \'\' OR label IS NULL',
            );
        } else {
            DB::table('processes')
                ->where(static function ($query): void {
                    $query->whereNull('label')->orWhere('label', '');
                })
                ->orderBy('id')
                ->chunkById(500, static function ($rows): void {
                    foreach ($rows as $row) {
                        if (! is_string($row->name) || $row->name === '') {
                            continue;
                        }

                        DB::table('processes')
                            ->where('id', $row->id)
                            ->update(['label' => $row->name]);
                    }
                });
        }
    }
};
