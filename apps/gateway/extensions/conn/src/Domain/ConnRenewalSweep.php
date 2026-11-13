<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Orbit\Extensions\Conn\Actions\AdmitConnCleanupAction;
use Orbit\Extensions\Conn\Actions\AdmitConnRenewalAction;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * The hourly renewal of the Gateway's sessions on container T3 servers ([Instance servers](/reference/conn#instance-servers)).
 * It renews a session within the margin of its end, or one that has ended, so a container that was stopped past its end gets
 * a session within an hour of its start, or within six hours when a try found it stopped. One tick admits a few, the earliest
 * end first, and one per Node at a time; a server whose last renewal failed comes after the others.
 */
final readonly class ConnRenewalSweep
{
    /** A session is renewed this many days before it ends; T3 issues 30-day sessions. */
    public const int MarginDays = 7;

    /** Instances made on one day reach the margin in the same hour; one tick admits at most this many. */
    public const int PerTick = 5;

    /** A stopped container is tried again no sooner than this, unless the renew route asks. */
    public const int StoppedRetryHours = 6;

    public function __construct(private AdmitConnRenewalAction $renewals, private AdmitConnCleanupAction $cleanups) {}

    /** @return list<int> the Operation ids it admitted */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $open = Operation::query()->where('kind', AdmitConnRenewalAction::Kind)->whereNotIn('status', OperationStatus::terminalValues())->pluck('target_id');
        $busyNodes = ConnEnvironment::query()->whereIn('id', $open)->pluck('node_id')->filter()->flip()->all();
        $admitted = [];
        $due = ConnEnvironment::query()->whereNotNull('instance_id')->whereNotNull('node_id')
            ->whereHas('instance', static fn (Builder $query) => $query->where('status', InstanceState::Active->value))
            ->where('admin_session_expires_at', '<', $now->addDays(self::MarginDays))
            ->where(static fn (Builder $query) => $query->whereNull('renewal_error_code')->orWhere('renewal_error_code', '!=', 'conn.environment_stopped')
                ->orWhere('renewal_checked_at', '<=', $now->subHours(self::StoppedRetryHours)))
            // A server whose last renewal failed goes after the others, so it never holds its Node's slot from them.
            ->orderByRaw('renewal_error_code is not null')->orderBy('admin_session_expires_at')->orderBy('id')->get();
        foreach ($due as $environment) {
            if (count($admitted) >= self::PerTick) {
                break;
            }
            $node = ValidatedData::integer($environment->node_id);
            if (isset($busyNodes[$node])) {
                continue;
            }
            try {
                $admitted[] = $this->renewals->execute($environment)->id;
                $busyNodes[$node] = true;
            } catch (ResourceOperationException $error) {
                Log::warning('conn.renewal_start_failed', ['environment_id' => $environment->environment_id, 'error_code' => $error->errorCode]);
            }
        }
        // A cleanup that waited for a pairing on the old session runs once that pairing has ended.
        foreach (ConnEnvironment::query()->whereNotNull('instance_id')->whereColumn('cleanup_version', '>', 'cleanup_completed_version')->get() as $environment) {
            try {
                $this->cleanups->execute($environment, Node::query()->findOrFail($environment->node_id));
            } catch (ResourceOperationException $error) {
                Log::info('conn.cleanup_deferred', ['environment_id' => $environment->environment_id, 'error_code' => $error->errorCode]);
            }
        }

        return $admitted;
    }
}
