<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Analytics\AnalyticsDisableInput;
use App\Domain\Analytics\AnalyticsEnableInput;
use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\Analytics\AnalyticsPlausibleCredentials;
use App\Domain\Analytics\AnalyticsTrackingHosts;
use App\Domain\Analytics\AnalyticsTrackingIntent;
use App\Domain\Analytics\AnalyticsTrackingUpstream;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Admit desired state only. Native Swamp jobs own publication. */
final readonly class RecordAnalyticsIntentAction
{
    public function __construct(private StartOperationAction $operations, private AnalyticsTrackingIntent $tracking,
        private SettingRepository $settings) {}

    /** @param list<string> $hosts
     * @param  list<array<string,mixed>>  $goals
     */
    public function execute(Instance $instance, bool $enabled, array $hosts = [], string $timezone = 'Europe/Amsterdam', array $goals = []): Operation
    {
        $analytics = AnalyticsTrackingUpstream::node();
        if ($enabled && ! $analytics instanceof Node) {
            throw new ResourceOperationException('analytics.role_missing', 'Analytics requires an active analytics role.', 409);
        }
        $owner = $instance->unsetRelation('routes')->authoritativeRoute();
        if ($enabled && ! $owner instanceof Route) {
            throw new ResourceOperationException('analytics.domain_required', 'Analytics requires an authoritative Instance domain.', 422);
        }
        $desired = $enabled ? ($hosts === [] ? [AnalyticsTrackingHosts::defaultFor($owner->domain)] : $hosts) : [];
        foreach ($desired as $host) {
            if (AnalyticsTrackingHosts::reason($host) !== null) {
                throw new ResourceOperationException('analytics.host_invalid', 'The tracking hostname is invalid.', 422);
            }
        }

        return DB::transaction(function () use ($instance, $enabled, $desired, $timezone, $goals, $owner, $analytics): Operation {
            $existing = $this->tracking->snapshot($instance);
            if (Route::query()->whereIn('domain', array_diff($desired, array_map(static fn (array $route): string => ValidatedData::string($route['domain']), $existing)))->exists()) {
                throw new ResourceOperationException('analytics.host_taken', 'A tracking hostname belongs to another Route.', 409);
            }
            $binding = $this->settings->get(new SettingScope(SettingScopeType::Gateway), "analytics.site.{$instance->id}");
            $previous = $binding === null ? null : json_decode($binding, true, flags: JSON_THROW_ON_ERROR);
            $nodeId = $analytics instanceof Node ? $analytics->id : $instance->node_id;
            $assignment = $analytics?->roles()->where('role', 'analytics')->where('status', 'active')->first();

            $input = [
                'instance_id' => $instance->id, 'instance_node_id' => $instance->node_id, 'enabled' => $enabled,
                'assignment_id' => $assignment?->id, 'base_url' => $analytics === null ? null : 'https://'.AnalyticsHostname::Value,
                'site' => $enabled ? ['domain' => $owner->domain, 'previous_domain' => is_array($previous) ? ($previous['domain'] ?? null) : null, 'timezone' => $timezone, 'goals' => $goals] : $previous,
                'previous_binding' => $binding, 'owner' => $owner === null ? null : $this->tracking->identity($owner),
                'hosts' => $desired, 'tracking_snapshot' => $existing,
                'dns_node_ids' => PrivateDnsPublicationNodes::query()->pluck('id')->all(),
            ];
            $intent = new Operation(['kind' => $enabled ? 'analytics.enable' : 'analytics.disable', 'target_type' => 'instance',
                'target_id' => $instance->id, 'node_id' => $nodeId, 'input' => $input]);
            $beforeSites = app(DevelopmentSiteRepository::class)->all();
            $plan = $this->tracking->materialize($intent);
            $input['tracking'] = $plan;
            $input['certificates'] = $plan['certificates'];
            $input['participant_node_ids'] = array_values(array_unique([$nodeId, ...$plan['caddy_node_ids'], ...$plan['dns_node_ids']]));
            if ($enabled) {
                $input['native_enable'] = app(AnalyticsEnableInput::class)->capture($plan, $beforeSites,
                    ValidatedData::object($input['site']), ValidatedData::string($input['base_url']));
                $input['execution_secrets'] = ['plausible_credentials' => ['vault' => 'orbit-control-inputs', 'key' => 'analytics-plausible-'.Str::uuid()]];
            } else {
                $input['native_disable'] = app(AnalyticsDisableInput::class)->capture($plan, $beforeSites,
                    is_array($previous) ? ValidatedData::object($previous) : null);
            }

            $operation = $this->operations->execute($intent->kind, 'instance', $instance->id, $nodeId, $input,
                'operator', request()->attributes->getString('orbit.request_id'));
            if ($enabled && $operation->wasRecentlyCreated) {
                $reference = ValidatedData::object(ValidatedData::object($operation->input['execution_secrets'])['plausible_credentials']);
                $credentials = app(AnalyticsPlausibleCredentials::class)->forSite(ValidatedData::string(ValidatedData::object($input['site'])['domain']), $goals !== []);
                app(SwampCommandStep::class)->executionCopy(ValidatedData::string($reference['vault']), ValidatedData::string($reference['key']),
                    json_encode($credentials, JSON_THROW_ON_ERROR));
            }

            return $operation;
        });
    }
}
