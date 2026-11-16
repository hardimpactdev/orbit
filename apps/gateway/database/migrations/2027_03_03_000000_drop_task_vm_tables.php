<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the task VM tables, which nothing reads or writes any more (docs/reference/old-paths.md#the-task-vm-tables):
 * `task_vms`, `task_vm_image_builds` and `task_vm_image_recipe`. No other table has a foreign key to them.
 *
 * The rollback creates the three tables again, empty, with the columns, defaults, indexes and foreign keys they had
 * before this migration. It cannot bring the rows back: the Gateway SQLite snapshot that the release takes before it
 * migrates holds them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('task_vms');
        Schema::dropIfExists('task_vm_image_builds');
        Schema::dropIfExists('task_vm_image_recipe');
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE "task_vms" (
                "id" integer primary key autoincrement not null, "group_id" integer, "host_node_id" integer, "node_id" integer,
                "provider" varchar not null, "name" varchar not null, "state" varchar not null, "address" varchar,
                "wireguard_ip" varchar not null, "pi_token" text, "error_code" varchar, "error_message" text, "ready_at" datetime,
                "destroyed_at" datetime, "created_at" datetime, "updated_at" datetime, "claimed_at" datetime, "warmed_at" datetime,
                "image_fingerprint" varchar, "size" varchar not null default 'normal', "topology" text, "topology_state" varchar,
                "topology_run" varchar, "topology_started_at" datetime, "topology_error" text, "publish_commit" varchar,
                "publish_branch" varchar, "publish_state" varchar, "publish_error" text, "publish_queued_at" datetime,
                "publish_started_at" datetime, "recipe_identity" varchar, "publish_error_code" varchar, "stop_confirmed_at" datetime,
                "image_build_id" integer, "image_fallback" tinyint(1) not null default '0', "slot_project_id" integer,
                "slot_number" integer, "wireguard_private_key" text, "ssh_host_private_key" text, "agent_secret" text,
                "capacity_pool" varchar not null default 'task', "runtime" varchar not null default 'vm',
                foreign key("group_id") references "tasks"("id"), foreign key("host_node_id") references "nodes"("id"),
                foreign key("node_id") references "nodes"("id") on delete set null
            );
            CREATE INDEX `task_vms_group_id_index` on `task_vms`(`group_id`);
            CREATE INDEX `task_vms_host_node_id_state_index` on `task_vms`(
              `host_node_id`,
              `state`
            );
            CREATE UNIQUE INDEX `task_vms_node_id_unique` on `task_vms`(`node_id`);
            CREATE INDEX `task_vms_state_index` on `task_vms`(`state`);
            CREATE UNIQUE INDEX task_vms_live_group_id_unique ON task_vms(
              group_id
            ) WHERE state != 'destroyed';
            CREATE UNIQUE INDEX task_vms_live_wireguard_ip_unique ON task_vms(
              wireguard_ip
            ) WHERE state != 'destroyed';
            CREATE UNIQUE INDEX task_vms_name_unique ON task_vms(name) WHERE state != 'destroyed';
            CREATE UNIQUE INDEX task_vms_host_slot_unique ON task_vms(host_node_id, slot_number) WHERE slot_number IS NOT NULL AND state != 'destroyed';
            CREATE INDEX "task_vms_capacity_pool_index" on "task_vms" ("capacity_pool");

            CREATE TABLE "task_vm_image_builds" (
                "id" integer primary key autoincrement not null, "host_node_id" integer not null, "source_alias" varchar,
                "source_fingerprint" varchar, "from_stock" tinyint(1) not null default '0', "builder_vm_name" varchar,
                "image_fingerprint" varchar, "current_step" varchar, "current_program" varchar,
                "state" varchar not null default 'running', "steps" text not null default '[]', "created_at" datetime,
                "updated_at" datetime, "submission_pending_at" varchar, "cleanup_started_at" datetime,
                "attempt" integer not null default '1', "recipe_identity" varchar, "recipe_json" text, "recipe_generation" integer,
                "images" text not null default '{}', "promoted_at" datetime, "fleet_convergence" text,
                "runtime" varchar not null default 'vm',
                foreign key("host_node_id") references "nodes"("id") on delete restrict
            );
            CREATE UNIQUE INDEX task_vm_image_builds_running_host_unique ON task_vm_image_builds(
              host_node_id
            ) WHERE state = 'running';
            CREATE INDEX `task_vm_image_builds_host_node_id_recipe_identity_index` on `task_vm_image_builds`(
              `host_node_id`,
              `recipe_identity`
            );

            CREATE TABLE "task_vm_image_recipe" (
                "id" integer not null, "identity" varchar, "recipe_json" text, "generation" integer not null default '0',
                primary key ("id")
            );
            SQL);
    }
};
