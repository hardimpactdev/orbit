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
use Spatie\LaravelData\Optional;

/**
 * Automatic deployment of an Instance ([Automatic deployments](/reference/deployments#automatic-deployments)):
 * whether it is on, the Project merge check that gates development and production, and what the last tick saw.
 * `warning` names an enabled Instance that deploys every commit without a check.
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
        public bool $eligible,
        public bool|Optional $developmentEligible,
        /** @var array<string, mixed>|Optional */
        public array|Optional $developmentRetries,
    ) {}

    public static function fromModel(Instance $instance): self
    {
        $automatic = AutomaticDeployState::of($instance);
        $state = $automatic->toArray();
        $placement = $instance->placementEnvironment();
        $eligible = app(AutomaticDeployPolicyWriter::class)->eligibilityRefusal($instance) === null;
        $developmentDefault = app(AutomaticDeployPolicyWriter::class)->developmentEligibilityRefusal($instance) === null;
        $enabled = $developmentDefault || ($placement === 'production' && $instance->auto_deploy);
        $history = $enabled ? new AutomaticDeployHistory($instance)->snapshot() : null;
        $gate = $instance->project->mergeCheckName();
        $pause = $automatic->pause();
        $active = $pause !== null && $pause['deployment_id'] > $instance->auto_deploy_resumed_after;

        return new self(
            eligible: $eligible,
            developmentEligible: $placement === 'production' ? new Optional : $developmentDefault,
            developmentRetries: $placement === 'production' ? new Optional : ($state['development_retries'] ?? ['manual_after' => 0, 'observed_through' => 0, 'unsettled' => [], 'commits' => []]),
            resumedAfter: $instance->auto_deploy_resumed_after,
            blocked: $history === null ? [] : array_values(array_unique([...$automatic->blocked(), ...$history->rolledAway])),
            historyVersion: $history?->version,
            enabled: $enabled,
            policyOwner: app(AutomaticDeployPolicyWriter::class)->value,
            gate: $gate,
            warning: $enabled && $gate === null
                ? ($placement === 'production'
                    ? 'The Project has no merge_check, so every new commit on the branch deploys without a check.'
                    : 'Project '.$instance->project->name.' has no merge check configured; every moved head deploys until one is set.')
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
