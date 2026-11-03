<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Settings\SettingScopeType;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Setting;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Records each automatic-deploy fire's events from its terminal result: an Activity entry, or a release alert for a
 * failed deploy, a pause or a stalled commit. No step calls the Gateway for them, and no other run starts.
 */
final readonly class AutomaticDeployFireProjection implements ScheduledResultProjection
{
    public const string Workflow = '78efdd30-08ce-4ffb-a8eb-c7259c96ad24';

    public const string StateKey = 'automatic-deploy.fires';

    /** Recent projected runs; a replayed fire changes nothing. */
    private const int RecentRuns = 500;

    public function __construct(private AutomaticDeployAlerts $alerts) {}

    public function activate(): void {}

    public function applyScheduled(string $workflowId, array $run, array $result): void
    {
        if ($workflowId !== self::Workflow) {
            throw new LogicException('Unknown automatic deploy schedule.');
        }
        $result = ValidatedData::object(json_decode(Arr::string($result, 'fire_json', '{}'), true, flags: JSON_THROW_ON_ERROR));
        $runId = Arr::string($run, 'id');
        DB::transaction(function () use ($runId, $result): void {
            $record = Setting::withoutEvents(fn (): Setting => Setting::query()->where('scope_type', SettingScopeType::Gateway)
                ->where('scope_id', 0)->where('key', self::StateKey)->lockForUpdate()->firstOrCreate([
                    'scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => self::StateKey,
                ], ['value' => '{}', 'is_secret' => false]));
            $runs = array_values(array_filter(Arr::array(ValidatedData::object(json_decode($record->value ?? '{}', true, 512, JSON_THROW_ON_ERROR)), 'runs', []), is_string(...)));
            if (in_array($runId, $runs, true)) {
                return;
            }
            foreach (Arr::array($result, 'events', []) as $event) {
                $this->record(ValidatedData::object($event));
            }
            $record->value = json_encode(['runs' => array_slice([...$runs, $runId], -self::RecentRuns)], JSON_THROW_ON_ERROR);
            $record->saveQuietly();
        });
    }

    /** @param array<string, mixed> $event */
    private function record(array $event): void
    {
        $validator = Validator::make($event, [
            'instance' => ['required', 'integer', 'min:1'],
            'key' => ['required', 'string', 'max:255'],
            'body' => ['required', 'array'],
            'body.event' => ['required', 'string', 'in:activity,failed,paused,stalled'],
            'body.deployment_id' => ['required_if:body.event,failed,paused', 'nullable', 'integer', 'min:1'],
            'body.commit' => ['required_if:body.event,stalled', 'nullable', 'string', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'body.since' => ['required_if:body.event,stalled', 'nullable', 'date'],
            'body.result' => ['required_if:body.event,activity,stalled', 'nullable', 'string', Rule::enum(AutomaticDeployResult::class)],
            'body.operation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'body.error_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body.message' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ]);
        $instance = Instance::query()->find($event['instance'] ?? null);
        if ($validator->fails() || ! $instance instanceof Instance) {
            report(new LogicException('An automatic deploy event in a fire result is invalid.'));

            return;
        }
        $body = ValidatedData::object($event['body']);
        $commit = ValidatedData::nullableString($body['commit'] ?? null);
        $outcome = static fn (): AutomaticDeployOutcome => new AutomaticDeployOutcome($instance->id,
            AutomaticDeployResult::from(ValidatedData::string($body['result'])), $commit,
            isset($body['operation_id']) ? ValidatedData::integer($body['operation_id']) : null,
            ValidatedData::nullableString($body['error_code'] ?? null), ValidatedData::nullableString($body['message'] ?? null));
        $deployment = static fn (): ?InstanceDeployment => InstanceDeployment::query()->where('instance_id', $instance->id)
            ->whereKey(isset($body['deployment_id']) ? ValidatedData::integer($body['deployment_id']) : 0)->first();
        match (ValidatedData::string($body['event'])) {
            'activity' => $this->alerts->activity($instance, $outcome()),
            'stalled' => $this->alerts->stalled($instance, ValidatedData::string($commit), ValidatedData::string($body['since']), $outcome()),
            'failed' => ($row = $deployment()) === null ? null : $this->alerts->failed($instance, $row),
            'paused' => ($row = $deployment()) === null ? null : $this->alerts->paused($instance, $row),
            default => null,
        };
    }
}
