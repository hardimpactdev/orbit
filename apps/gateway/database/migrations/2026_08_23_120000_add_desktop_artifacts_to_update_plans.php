<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('operation_update_plans', static function (Blueprint $table): void {
            $table->json('desktop_artifacts')->nullable()->after('agent_artifacts');
        });
    }
};
