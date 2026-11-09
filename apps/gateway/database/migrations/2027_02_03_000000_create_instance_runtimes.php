<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The records of development containers (docs/reference/development-containers.md#records): each Node's Instance
 * bridge network, and one runtime row per container Instance. No row exists until the container runtime creates one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('instance_bridge')->nullable()->unique();
        });

        Schema::create('instance_runtimes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instance_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('node_id')->constrained()->restrictOnDelete();
            $table->string('incus_project');
            $table->string('container_name');
            $table->string('bridge_address');
            $table->unsignedInteger('t3_port')->nullable();
            $table->string('runtime_version');
            $table->string('agent_version');
            $table->foreignId('clone_source_instance_id')->nullable()->constrained('instances')->restrictOnDelete();
            $table->string('power_state');
            $table->timestamps();
            $table->unique(['node_id', 'incus_project', 'container_name']);
            $table->unique(['node_id', 'bridge_address']);
            $table->unique(['node_id', 't3_port']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER nodes_instance_bridge_update
            BEFORE UPDATE OF instance_bridge ON nodes
            WHEN OLD.instance_bridge IS NOT NULL AND NEW.instance_bridge IS NOT OLD.instance_bridge
            BEGIN SELECT RAISE(ABORT, 'A Node keeps its Instance bridge.'); END;

            CREATE TRIGGER instance_runtimes_insert
            BEFORE INSERT ON instance_runtimes
            WHEN NEW.node_id IS NOT (SELECT node_id FROM instances WHERE id = NEW.instance_id)
                OR NEW.power_state NOT IN ('running', 'stopped')
                OR (NEW.t3_port IS NOT NULL AND (NEW.t3_port < 3800 OR NEW.t3_port > 3999))
                OR (NEW.t3_port IS NOT NULL AND (SELECT name FROM instances WHERE id = NEW.instance_id) = 'default')
                OR (NEW.clone_source_instance_id IS NOT NULL AND (
                    (SELECT name FROM instances WHERE id = NEW.instance_id) = 'default'
                    OR NOT EXISTS (
                        SELECT 1 FROM instances source
                        JOIN instances clone ON clone.id = NEW.instance_id
                        JOIN instance_runtimes source_runtime ON source_runtime.instance_id = source.id
                        WHERE source.id = NEW.clone_source_instance_id
                            AND source.name = 'default'
                            AND source.project_id = clone.project_id
                            AND source.node_id = clone.node_id
                            AND source_runtime.clone_source_instance_id IS NULL
                    )
                ))
            BEGIN SELECT RAISE(ABORT, 'Invalid Instance runtime.'); END;

            CREATE TRIGGER instance_runtimes_update
            BEFORE UPDATE ON instance_runtimes
            WHEN NEW.instance_id IS NOT OLD.instance_id
                OR NEW.node_id IS NOT OLD.node_id
                OR NEW.incus_project IS NOT OLD.incus_project
                OR NEW.container_name IS NOT OLD.container_name
                OR NEW.bridge_address IS NOT OLD.bridge_address
                OR NEW.t3_port IS NOT OLD.t3_port
                OR NEW.clone_source_instance_id IS NOT OLD.clone_source_instance_id
                OR NEW.power_state NOT IN ('running', 'stopped')
            BEGIN SELECT RAISE(ABORT, 'An Instance runtime keeps its container, address, port and clone source.'); END;

            CREATE TRIGGER instances_runtime_placement_update
            BEFORE UPDATE OF node_id, project_id, name ON instances
            WHEN EXISTS (SELECT 1 FROM instance_runtimes WHERE instance_id = OLD.id)
                AND (NEW.node_id IS NOT OLD.node_id OR NEW.project_id IS NOT OLD.project_id OR NEW.name IS NOT OLD.name)
            BEGIN SELECT RAISE(ABORT, 'A container Instance keeps its Node, Project and name.'); END;
            SQL);
    }

    public function down(): void
    {
        foreach (['instances_runtime_placement_update', 'instance_runtimes_update', 'instance_runtimes_insert', 'nodes_instance_bridge_update'] as $trigger) {
            DB::statement("DROP TRIGGER {$trigger}");
        }
        Schema::dropIfExists('instance_runtimes');
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropUnique(['instance_bridge']);
            $table->dropColumn('instance_bridge');
        });
    }
};
