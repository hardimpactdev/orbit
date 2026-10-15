<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic deployments of production Instances ([Automatic deployments](/reference/deployments#automatic-deployments)).
 * An Instance opts in with `auto_deploy`. A rollback after the deployment `auto_deploy_resumed_after` pauses it. The
 * tick keeps its last observation in `auto_deploy_state`. A history row names whether it is a deploy or a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances', function (Blueprint $table): void {
            $table->boolean('auto_deploy')->default(false);
            $table->unsignedBigInteger('auto_deploy_resumed_after')->default(0);
            $table->json('auto_deploy_state')->nullable();
        });

        Schema::table('instance_deployments', function (Blueprint $table): void {
            $table->string('kind')->default('deploy');
        });
    }

    public function down(): void
    {
        Schema::table('instance_deployments', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });

        Schema::table('instances', function (Blueprint $table): void {
            $table->dropColumn(['auto_deploy', 'auto_deploy_resumed_after', 'auto_deploy_state']);
        });
    }
};
