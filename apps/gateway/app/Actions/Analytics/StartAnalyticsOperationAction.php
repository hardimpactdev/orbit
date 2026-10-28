<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Integrations\StartIntegrationOperationAction;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Analytics\AnalyticsSecretManager;
use App\Domain\Analytics\AnalyticsStorageConnection;
use App\Domain\Analytics\AnalyticsStorageProcessGuard;
use App\Domain\Analytics\PlausibleClickhouseConfiguration;
use App\Domain\Analytics\PlausibleProcess;
use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Integrations\IntegrationPlan;
use App\Domain\Integrations\IntegrationProcessIntent;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Analytics\AnalyticsFootprint;
use App\Infrastructure\Caddy\Build\Sources\AnalyticsCaddySiteSource;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Validates and records analytics intent. The storage Processes and their data stay operator-owned. */
final readonly class StartAnalyticsOperationAction
{
    public function __construct(private StartIntegrationOperationAction $operations, private IntegrationProcessIntent $processes,
        private ProcessOperationAdmission $processOperations, private AnalyticsRoleSettingsRepository $settings,
        private AnalyticsStorageProcessGuard $storage, private AnalyticsSecretManager $secrets,
        private GatewayCertificateIssuer $certificates,
        private NodeFirewallRuleCatalog $firewall) {}

    public function execute(Node $node, NodeRole|int $assignment, string $verb = 'enable', bool $unreachable = false, ?string $previousVersion = null, ?Operation $existing = null): Operation
    {
        return DB::transaction(function () use ($node, $assignment, $verb, $unreachable, $previousVersion, $existing): Operation {
            $remove = $verb === 'disable';
            $plan = new IntegrationPlan($node->id);
            $input = ['node_id' => $node->id, 'assignment_id' => $assignment instanceof NodeRole ? $assignment->id : $assignment, 'unreachable' => $unreachable, 'previous_version' => $previousVersion];
            if ($remove) {
                if (! $unreachable) {
                    $plan->child('analytics-withdraw', 'route.caddy_converge', $node->id, ['reason' => 'analytics removal']);
                    $plan->agent('analytics-certificate-remove', 'caddy.remove-certificate', ['directory' => AnalyticsFootprint::CertificateCurrentDirectory]);
                    $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', PlausibleProcess::NAME)->first();
                    if ($process !== null) {
                        $plan->child('analytics-process-remove', 'process.destroy', $node->id, $this->processes->remove($process));
                    }
                    $this->firewall($plan, $node, true);
                }
            } else {
                $settings = $this->settings->find($node) ?? throw new ResourceOperationException('analytics.settings_missing', 'No analytics storage Processes are recorded.', 422);
                $storage = $this->storage->assert($settings->postgresProcessId, $settings->clickhouseProcessId);
                $clickhouse = $storage['clickhouse'];
                $missing = PlausibleClickhouseConfiguration::missingMounts($clickhouse->runtime_config['volumes'] ?? [], $clickhouse->name);
                $recovering = $clickhouse->status === LifecycleStatus::Failed;
                if ($missing !== []) {
                    $clickhouse->update(['runtime_config' => [...$clickhouse->runtime_config, 'volumes' => [...(is_array($clickhouse->runtime_config['volumes'] ?? null) ? $clickhouse->runtime_config['volumes'] : []), ...$missing]]]);
                }
                $clickhousePlan = new IntegrationPlan($clickhouse->owner_id);
                $clickhousePlan->agent('analytics-clickhouse-files', 'analytics.configure-clickhouse', []);
                $child = ['type' => 'child', 'key' => 'analytics-clickhouse-process', 'kind' => 'process.update', 'node_id' => $clickhouse->owner_id,
                    'input' => $this->processOperations->inputFor($clickhouse, 'update')];
                if ($missing === [] && ! $recovering) {
                    $child['when'] = ['step' => 'analytics-clickhouse-files', 'field' => 'changed', 'equals' => true];
                }
                $clickhousePlan->flow[] = $child;
                if ($missing === [] && $clickhouse->desired_state === DesiredProcessState::Running) {
                    $clickhousePlan->flow[] = ['type' => 'child', 'key' => 'analytics-clickhouse-restart', 'kind' => 'process.restart', 'node_id' => $clickhouse->owner_id,
                        'input' => $this->processOperations->inputFor($clickhouse, 'restart'),
                        'when' => ['step' => 'analytics-clickhouse-files', 'field' => 'changed', 'equals' => true]];
                }
                $clickhousePlan->receipt('active');
                $input['clickhouse_node_id'] = $clickhouse->owner_id;
                $plan->child('analytics-clickhouse', 'analytics.clickhouse_converge', $clickhouse->owner_id,
                    ['node_id' => $clickhouse->owner_id, 'effects' => $clickhousePlan->effects, 'flow' => $clickhousePlan->flow]);
                $this->firewall($plan, $node, false);
                $intent = $this->processes->converge($node, PlausibleProcess::data($node, $this->settings->version($node),
                    AnalyticsStorageConnection::from($storage['postgres'], $storage['clickhouse']), $this->secrets->secretKeyBase($node)));
                $plan->child('analytics-process', $intent['kind'], $node->id, $intent['input']);
                $plan->agent('analytics-health', 'http.await-healthy', ['url' => 'http://'.$node->wireguard_ip.':'.PlausibleProcess::PORT.'/api/health', 'attempts' => 90, 'interval_seconds' => 2]);
                $plan->agent('analytics-caddy-package', 'caddy.install-package', AgentCaddyPackage::input());
                $certificate = $this->certificates->issue('analytics.orbit', ValidatedData::string($node->wireguard_ip));
                $plan->agent('analytics-certificate', 'caddy.install-certificate', [
                    'directory' => AnalyticsFootprint::CertificateCurrentDirectory, 'certificate_file' => 'analytics.pem', 'key_file' => 'analytics.key', 'service' => 'caddy',
                ], ['certificate' => $this->read($certificate->certificatePath), 'key' => $this->read($certificate->privateKeyPath)]);
                $plan->gateway('certificate-record', 'integration.certificate-record', ['slug' => AnalyticsCaddySiteSource::CertificateSlug]);
                $plan->child('analytics-publication', 'route.caddy_converge', $node->id, ['reason' => 'analytics publication']);
            }
            $dns = NodeRole::query()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->sole();
            $input['dns_node_id'] = $dns->node_id;
            $plan->child('analytics-dns', 'route.private_dns_publish', $dns->node_id, ['reason' => 'analytics intent']);
            $plan->receipt($remove ? 'absent' : 'active');

            if ($existing !== null) { return $this->operations->prepare($existing, $input, $plan); }
            return $this->operations->execute('analytics.'.$verb, 'analytics', $node->id, $node->id, $input, $plan);
        });
    }

    private function firewall(IntegrationPlan $plan, Node $node, bool $remove): void
    {
        $rules = $remove ? $this->firewall->ownedByRole($node, RoleName::Analytics) : $this->firewall->forRole($node, RoleName::Analytics);
        $baseline = array_map(static fn ($rule): string => $rule->shape->comment, $this->firewall->forNode($node));
        $rules = array_values(array_filter($rules, static fn ($rule): bool => ! in_array($rule->shape->comment, $baseline, true)));
        $shapes = array_map(static fn ($rule): array => $rule->shape->toAgentInput(), $rules);
        $plan->agent('analytics-firewall', 'firewall.rules', ['desired' => $remove ? [] : array_map(static fn (array $shape): array => ['shape' => $shape, 'replace_port' => false], $shapes), 'remove' => $remove ? $shapes : [], 'enable' => false]);
    }

    private function read(string $path): string
    {
        $value = file_get_contents($path);
        if (! is_string($value) || $value === '') {
            throw new ResourceOperationException('analytics.certificate_read_failed', 'The admitted certificate is unreadable.', 422);
        }

        return $value;
    }
}
