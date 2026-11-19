<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Metrics\PrometheusConfigRenderer;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

uses(LazilyRefreshDatabase::class);

function insightMetricsNode(string $name, string $ip, bool $metrics): Node
{
    return Node::withoutEvents(function () use ($name, $ip, $metrics): Node {
        $node = Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip,
            'wireguard_ip' => $ip, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fingerprint']);
        if ($metrics) {
            $node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
        }

        return $node;
    });
}

/** @param array<string, int|float|null> $current */
function insightMetricsTotal(?Project $project, string $period, string $computedAt, array $current): void
{
    DB::table('task_insight_totals')->insert(['scope' => $project === null ? 'all' : 'project:'.$project->id, 'project_id' => $project?->id,
        'period' => $period, 'computed_at' => $computedAt, 'payload' => json_encode(['period' => $period, 'current' => $current, 'previous' => ['tasks' => 99]])]);
}

it('exposes the current summary of each stored scope and period from the totals alone', function (): void {
    $scraper = insightMetricsNode('metrics', '10.44.0.7', true);
    $project = Project::query()->create(['name' => 'Web', 'slug' => 'web', 'code' => 'WEB', 'repository_url' => 'https://github.com/example/web.git', 'default_branch' => 'main', 'root' => 'public']);
    insightMetricsTotal(null, '7d', '2026-11-19 10:00:00', ['tasks' => 12, 'merged' => 9, 'tokens' => 120000, 'cost_usd' => 3.25,
        'yield' => 0.75, 'wasted_share' => 0.125, 'lead_time_ms' => 5400000, 'pr_merge_wait_ms' => 1500, 'delivery_time_ms' => null]);
    insightMetricsTotal($project, '30d', '2026-11-19 10:05:00', ['tasks' => 0, 'merged' => 0, 'tokens' => 0, 'cost_usd' => null, 'yield' => null]);
    $tables = [];
    DB::listen(static function (QueryExecuted $query) use (&$tables): void {
        preg_match_all('/(?:from|join)\s+"?(\w+)"?/i', $query->sql, $matches);
        array_push($tables, ...$matches[1]);
    });

    $response = $this->withServerVariables(['REMOTE_ADDR' => $scraper->wireguard_ip])->get('/api/v1/metrics/task-insights');

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
    expect(str_replace("\r", '', (string) $response->getContent()))->toBe(<<<'TEXT'
        # HELP orbit_task_insights_computed_timestamp_seconds When the stored totals were computed, as a Unix time.
        # TYPE orbit_task_insights_computed_timestamp_seconds gauge
        orbit_task_insights_computed_timestamp_seconds{project="all",period="7d"} 1795082400
        orbit_task_insights_computed_timestamp_seconds{project="WEB",period="30d"} 1795082700
        # HELP orbit_task_insights_tasks Ended task groups in the period.
        # TYPE orbit_task_insights_tasks gauge
        orbit_task_insights_tasks{project="all",period="7d"} 12
        orbit_task_insights_tasks{project="WEB",period="30d"} 0
        # HELP orbit_task_insights_merged Merged task groups in the period.
        # TYPE orbit_task_insights_merged gauge
        orbit_task_insights_merged{project="all",period="7d"} 9
        orbit_task_insights_merged{project="WEB",period="30d"} 0
        # HELP orbit_task_insights_tokens Tokens of every agent thread of the ended task groups.
        # TYPE orbit_task_insights_tokens gauge
        orbit_task_insights_tokens{project="all",period="7d"} 120000
        orbit_task_insights_tokens{project="WEB",period="30d"} 0
        # HELP orbit_task_insights_cost_usd Cost in USD of every agent thread of the ended task groups.
        # TYPE orbit_task_insights_cost_usd gauge
        orbit_task_insights_cost_usd{project="all",period="7d"} 3.25
        # HELP orbit_task_insights_yield_ratio Merged task groups divided by ended task groups.
        # TYPE orbit_task_insights_yield_ratio gauge
        orbit_task_insights_yield_ratio{project="all",period="7d"} 0.75
        # HELP orbit_task_insights_wasted_ratio Tokens of unmerged task groups and replaced implementer threads, divided by tokens.
        # TYPE orbit_task_insights_wasted_ratio gauge
        orbit_task_insights_wasted_ratio{project="all",period="7d"} 0.125
        # HELP orbit_task_insights_lead_time_median_seconds Median ready to merge time of merged task groups.
        # TYPE orbit_task_insights_lead_time_median_seconds gauge
        orbit_task_insights_lead_time_median_seconds{project="all",period="7d"} 5400
        # HELP orbit_task_insights_pr_merge_wait_median_seconds Median pull request open to merge time.
        # TYPE orbit_task_insights_pr_merge_wait_median_seconds gauge
        orbit_task_insights_pr_merge_wait_median_seconds{project="all",period="7d"} 1.5

        TEXT);
    expect(array_diff(array_unique($tables), ['nodes', 'node_roles', 'task_insight_totals', 'projects']))->toBe([]);
});

it('answers an empty exposition before any totals are stored', function (): void {
    $scraper = insightMetricsNode('metrics', '10.44.0.7', true);

    $this->withServerVariables(['REMOTE_ADDR' => $scraper->wireguard_ip])->get('/api/v1/metrics/task-insights')
        ->assertOk()->assertContent('');
});

it('refuses every caller but the active Metrics Node', function (): void {
    $peer = insightMetricsNode('app', '10.44.0.8', false);
    insightMetricsTotal(null, '7d', '2026-11-19 10:00:00', ['tasks' => 12]);

    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->get('/api/v1/metrics/task-insights')
        ->assertForbidden()->assertJsonPath('error.code', 'metrics.scraper_required');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/api/v1/metrics/task-insights')
        ->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    $retired = insightMetricsNode('old-metrics', '10.44.0.6', false);
    $retired->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Failed]);
    $this->withServerVariables(['REMOTE_ADDR' => $retired->wireguard_ip])->get('/api/v1/metrics/task-insights')
        ->assertForbidden()->assertJsonPath('error.code', 'metrics.scraper_required');
});

it('renders the task insights scrape job against the Gateway, trusting the Orbit root alone', function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn("-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n");

    $config = Yaml::parse(new PrometheusConfigRenderer()->render([['name' => 'app', 'address' => '10.44.0.8']]));

    expect(array_column($config['scrape_configs'], 'job_name'))->toBe(['orbit-node-exporter', 'orbit-cadvisor', 'orbit-task-insights'])
        ->and($config['scrape_configs'][2])->toBe([
            'job_name' => 'orbit-task-insights',
            'scrape_interval' => '60s',
            'scrape_timeout' => '10s',
            'sample_limit' => 20000,
            'scheme' => 'https',
            'metrics_path' => '/api/v1/metrics/task-insights',
            'tls_config' => ['ca' => "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n", 'server_name' => 'gateway.orbit'],
            'static_configs' => [['targets' => ['gateway.orbit:443']]],
        ]);
});
