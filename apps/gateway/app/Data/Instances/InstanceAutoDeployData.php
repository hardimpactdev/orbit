<?php

declare(strict_types=1);

namespace App\Data\Instances;

use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Models\Instance;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Automatic deployment of an Instance ([Automatic deployments](/reference/deployments#automatic-deployments)):
 * whether it is on, the merge check that gates it, and what the last tick saw. A development default always deploys
 * automatically, without a gate. `warning` names a production Instance that deploys every commit without a check.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class InstanceAutoDeployData extends Data
{
    public function __construct(
        public bool $enabled,
        public string $policyOwner,
        public ?string $gate,
        public ?string $warning,
        public ?string $result,
        public ?string $commit,
        public ?string $head,
        public ?string $since,
        public ?string $errorCode,
        public ?string $message,
        public ?InstanceAutoDeployPauseData $pause,
        public int $resumedAfter,
        /** @var list<string> */
        public array $blocked,
        public ?string $historyVersion,
    ) {}

    public static function fromModel(Instance $instance): self
    {
        $automatic = AutomaticDeployState::of($instance);
        $state = $automatic->toArray();
        $placement = $instance->placementEnvironment();
        $developmentDefault = $placement === 'development' && $instance->name === 'default';
        $enabled = $developmentDefault || ($placement === 'production' && $instance->auto_deploy);
        $history = $enabled ? new AutomaticDeployHistory($instance)->snapshot() : null;
        $gate = $developmentDefault ? null : $instance->project->mergeCheckName();
        $pause = $automatic->pause();
        $active = $pause !== null && $pause['deployment_id'] > $instance->auto_deploy_resumed_after;

        return new self(
            resumedAfter: $instance->auto_deploy_resumed_after,
            blocked: $history === null ? $automatic->smokeBlocked() : array_values(array_unique([...$automatic->blocked(), ...$history->rolledAway])),
            historyVersion: $history?->version,
            enabled: $enabled,
            policyOwner: $developmentDefault ? AutomaticDeployPolicyWriter::Gateway->value : app(AutomaticDeployPolicyWriter::class)->value,
            gate: $gate,
            warning: $enabled && ! $developmentDefault && $gate === null
                ? 'The Project has no merge_check, so every new commit on the branch deploys without a check.'
                : null,
            result: $enabled ? self::string($state['result'] ?? null) : null,
            commit: $enabled ? self::string($state['commit'] ?? null) : null,
            head: $enabled ? self::string($state['head'] ?? null) : null,
            since: $enabled ? self::string($state['since'] ?? null) : null,
            errorCode: $enabled ? self::string($state['error_code'] ?? null) : null,
            message: $enabled ? self::string($state['message'] ?? null) : null,
            pause: $enabled && $active ? new InstanceAutoDeployPauseData($pause['reason'], $pause['deployment_id'], $pause['commit'], $pause['paused_at']) : null,
        );
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
