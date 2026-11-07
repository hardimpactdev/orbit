<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outside hosts a task group's VM connected to (docs/reference/task-vm-egress-log.md). One row for each destination,
 * port and protocol; `port` 0 and `protocol` '' mean a name that nothing connected to. Rows stay with the group after its
 * VM and Node are deleted, and are never removed by a later report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_group_egress', static function (Blueprint $table): void {
            $table->increments('id');
            $table->integer('task_id');
            $table->string('vm_name', 63);
            $table->string('destination', 253);
            $table->string('kind', 7);
            $table->unsignedSmallInteger('port')->default(0);
            $table->string('protocol', 3)->default('');
            $table->text('addresses');
            $table->text('sources');
            $table->text('flags');
            $table->dateTime('first_seen_at');
            $table->unique(['task_id', 'destination', 'port', 'protocol']);
            $table->foreign(['task_id'])->references(['id'])->on('tasks')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_group_egress');
    }
};
