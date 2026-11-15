<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The container server (docs/reference/development-containers.md#container-server): the Project's choice, and the server each runtime
 * records at its reservation. Every runtime with a T3 port runs the T3 server today; one without a port runs no server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('container_server', 16)->default('t3');
        });
        Schema::table('instance_runtimes', function (Blueprint $table) {
            $table->string('container_server', 16)->nullable();
        });
        DB::table('instance_runtimes')->whereNotNull('t3_port')->update(['container_server' => 't3']);
        // A runtime records its server exactly when it has a T3 port, and only a known server; it keeps the value it was made with.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER instance_runtimes_container_server_insert
            BEFORE INSERT ON instance_runtimes
            WHEN (NEW.container_server IS NULL) IS NOT (NEW.t3_port IS NULL)
                OR (NEW.container_server IS NOT NULL AND NEW.container_server NOT IN ('t3', 'conn'))
            BEGIN SELECT RAISE(ABORT, 'An Instance runtime records its container server, t3 or conn, exactly when it has a T3 port.'); END;

            CREATE TRIGGER instance_runtimes_container_server_update
            BEFORE UPDATE OF container_server ON instance_runtimes
            WHEN NEW.container_server IS NOT OLD.container_server
            BEGIN SELECT RAISE(ABORT, 'An Instance runtime keeps its container server.'); END;
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER instance_runtimes_container_server_update');
        DB::statement('DROP TRIGGER instance_runtimes_container_server_insert');
        Schema::table('instance_runtimes', function (Blueprint $table) {
            $table->dropColumn('container_server');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('container_server');
        });
    }
};
