<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array DOCKER_RUNTIME_TOOLS = [
        'mailpit',
        'mysql',
        'postgres',
        'redis',
        'reverb',
        'rustfs',
    ];

    public function up(): void
    {
        Schema::table('node_tools', function (Blueprint $table): void {
            $table->string('instance_key')->nullable()->after('name');
            $table->string('version_family')->nullable()->after('instance_key');
            $table->string('runtime')->nullable()->after('version_family');
            $table->json('runtime_config')->nullable()->after('runtime');
        });

        DB::table('node_tools')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(100, function ($tools): void {
                foreach ($tools as $tool) {
                    DB::table('node_tools')
                        ->where('id', $tool->id)
                        ->update([
                            'instance_key' => "{$tool->name}:default",
                            'runtime' => $this->defaultRuntimeForTool((string) $tool->name),
                        ]);
                }
            });

        Schema::table('node_tools', function (Blueprint $table): void {
            $table->dropUnique('node_tools_node_id_name_unique');
            $table->unique(['node_id', 'name', 'instance_key']);
        });
    }

    public function down(): void
    {
        Schema::table('node_tools', function (Blueprint $table): void {
            $table->dropUnique('node_tools_node_id_name_instance_key_unique');
            $table->dropColumn([
                'instance_key',
                'version_family',
                'runtime',
                'runtime_config',
            ]);
            $table->unique(['node_id', 'name']);
        });
    }

    private function defaultRuntimeForTool(string $name): ?string
    {
        return in_array($name, self::DOCKER_RUNTIME_TOOLS, true) ? 'docker' : null;
    }
};
