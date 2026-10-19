<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::create('ai_router_cutovers', function (Blueprint $table): void {
                $table->foreignId('node_id')->primary()->constrained('nodes')->cascadeOnDelete();
                // Intent must survive deletion of the retired Process until its replacement is published.
                $table->unsignedBigInteger('process_id');
            });
            $nodeId = DB::table('settings')->where('scope_type', 'gateway')->where('scope_id', 0)->where('key', 'proxycli.node_id')->value('value');
            $enabled = DB::table('settings')->where('scope_type', 'gateway')->where('scope_id', 0)->where('key', 'proxycli.enabled')->value('value') === '1';
            $collectors = $enabled
                ? DB::table('processes')->where('owner_type', Node::class)->where('owner_id', $nodeId)->where('name', 'cli-proxy-api-collector')->get()
                : collect();
            foreach ($collectors as $collector) {
                $config = json_decode($collector->runtime_config, true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($config) || $collector->runtime !== 'systemd' || ($config['command'] ?? null) !== ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py']) {
                    throw new RuntimeException('The stored collector does not match the managed collector specification.');
                }
                if (DB::table('processes')->where('owner_type', $collector->owner_type)->where('owner_id', $collector->owner_id)->where('name', 'ai-router-collector')->exists()) {
                    throw new RuntimeException('The AI router collector name is already in use.');
                }
            }

            $keys = [
                'extension.proxycli.enabled' => 'extension.ai-router.enabled',
                'proxycli.enabled' => 'ai_router.enabled',
                'proxycli.node_id' => 'ai_router.node_id',
                'proxycli.cache_connection' => 'ai_router.cache_connection',
                'proxycli.cliproxy_url' => 'ai_router.router_url',
                'proxycli.cliproxy_management_key' => 'ai_router.management_key',
                'proxycli.read_token' => 'ai_router.read_token',
                'proxycli.control_token' => 'ai_router.control_token',
            ];
            foreach (DB::table('settings')->where('key', 'like', 'compute.model_proxy.anchor.%')->get(['key']) as $anchor) {
                $keys[$anchor->key] = 'ai_router.anchor.'.substr($anchor->key, strlen('compute.model_proxy.anchor.'));
            }
            foreach ($keys as $old => $new) {
                foreach (DB::table('settings')->where('key', $old)->get() as $setting) {
                    if (DB::table('settings')->where('scope_type', $setting->scope_type)->where('scope_id', $setting->scope_id)->where('key', $new)->exists()) {
                        throw new RuntimeException("Both retired and current AI router settings exist for [{$new}].");
                    }
                    DB::table('settings')->where('id', $setting->id)->update(['key' => $new]);
                }
            }
            // The old certificate covers a different hostname. Publication must issue a new leaf before Caddy uses it.
            DB::table('settings')->where('key', 'caddy.certificate.proxycli')->delete();

            foreach ($collectors as $collector) {
                DB::table('ai_router_cutovers')->insert(['node_id' => $collector->owner_id, 'process_id' => $collector->id]);
            }

            DB::statement('ALTER TABLE task_vms RENAME COLUMN model_proxy_origin TO ai_router_origin');

            // A footprint receipt is a digest of the retired source. Do not reuse it for renamed code.
            foreach (DB::table('node_footprints')->whereNotNull('artifacts')->get(['id', 'artifacts']) as $footprint) {
                $artifacts = json_decode($footprint->artifacts, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($artifacts) && array_key_exists('proxycli', $artifacts)) {
                    unset($artifacts['proxycli']);
                    DB::table('node_footprints')->where('id', $footprint->id)->update(['artifacts' => json_encode($artifacts, JSON_THROW_ON_ERROR)]);
                }
            }

            foreach (Schema::getTables() as $table) {
                $name = $table['name'];
                if (! Schema::hasColumn($name, 'error_code')) {
                    continue;
                }
                foreach (DB::table($name)->where('error_code', 'like', 'proxycli.%')->distinct()->pluck('error_code') as $code) {
                    if (! is_string($code)) {
                        throw new RuntimeException('A stored AI router error code is not a string.');
                    }
                    DB::table($name)->where('error_code', $code)->update(['error_code' => 'ai_router.'.substr($code, strlen('proxycli') + 1)]);
                }
                DB::table($name)->where('error_code', 'process.required_by_proxycli')->update(['error_code' => 'process.required_by_ai_router']);
            }
        });
    }

    public function down(): never
    {
        throw new RuntimeException('Restore the pre-migration Gateway backup to undo the AI router rename.');
    }
};
