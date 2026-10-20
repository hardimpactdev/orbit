<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Models\DatabaseConnection;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Support\Facades\DB;

final class RecordedAiRouterState
{
    public static function seed(Node $node, DatabaseConnection $connection, string $origin = 'http://10.44.0.3:8317'): Process
    {
        $old = require base_path('tests/Fixtures/ai-router/old-state.php');
        $old['settings']['ai_router.node_id'] = (string) $node->id;
        $old['settings']['ai_router.cache_connection'] = $connection->slug;
        $old['settings']['ai_router.router_url'] = $origin;
        $repository = app(SettingRepository::class);
        $scope = new SettingScope(SettingScopeType::Gateway);
        foreach ($old['settings'] as $key => $value) {
            $secret = in_array($key, ['ai_router.management_key', 'ai_router.read_token', 'ai_router.control_token'], true);
            $repository->put($scope, $key, $value, $secret ? SettingValueProtection::Secret : SettingValueProtection::Plain);
        }
        $repository->put($scope, 'ai_router.anchor.'.hash('sha256', $origin), 'synthetic-anchor', SettingValueProtection::Secret);
        $old['environment']['PROXYCLI_CLIPROXY_URL'] = $origin;
        $old['environment']['PROXYCLI_CACHE_HOST'] = $connection->host;
        $old['environment']['PROXYCLI_CACHE_PORT'] = (string) $connection->port;
        $old['environment']['PROXYCLI_CACHE_USERNAME'] = $connection->username ?? '';
        $old['environment']['PROXYCLI_CACHE_PASSWORD'] = $connection->password ?? '';
        $process = Process::query()->forceCreate([
            ...$old['collector'], 'owner_id' => $node->id,
            'runtime_config' => ['command' => $old['command'], 'environment' => $old['environment']],
        ]);
        DB::table('ai_router_process_sources')->insert(['process_id' => $process->id, 'source_revision' => str_repeat('a', 64), 'activation_pending' => false]);
        DB::table('node_footprints')->insert(['node_id' => $node->id, 'artifacts' => json_encode($old['footprint'], JSON_THROW_ON_ERROR)]);
        DB::table('ai_router_cutovers')->insert(['node_id' => $node->id, 'process_id' => $process->id]);

        return $process;
    }
}
