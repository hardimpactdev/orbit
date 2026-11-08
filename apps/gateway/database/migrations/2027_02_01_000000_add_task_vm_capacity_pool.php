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
        Schema::table('task_vms', function (Blueprint $table): void {
            $table->string('capacity_pool')->default('task')->index();
        });
        DB::table('task_vms')->whereNull('group_id')->orWhereIn('group_id', DB::table('operations')
            ->where('kind', 'proof.command')->where('input->ci_reserve', true)->select('target_id'))
            ->update(['capacity_pool' => 'ci']);
    }
};
