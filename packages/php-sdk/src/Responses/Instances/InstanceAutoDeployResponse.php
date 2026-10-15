<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

/**
 * Automatic deployment of an Instance: whether it is on, the merge check that gates it, and what the Gateway's last
 * automatic deployment tick saw.
 *
 * @phpstan-type AutoDeployPause array{reason: string, deployment_id: int, commit: string|null, paused_at: string}
 * @phpstan-type AutoDeployRecord array{
 *     enabled: bool,
 *     gate: string|null,
 *     warning: string|null,
 *     result: string|null,
 *     commit: string|null,
 *     head: string|null,
 *     since: string|null,
 *     error_code: string|null,
 *     message: string|null,
 *     pause: AutoDeployPause|null
 * }
 */
final readonly class InstanceAutoDeployResponse
{
    /** @param AutoDeployPause|null $pause */
    public function __construct(
        public bool $enabled,
        public ?string $gate,
        public ?string $warning,
        public ?string $result,
        public ?string $commit,
        public ?string $head,
        public ?string $since,
        public ?string $errorCode,
        public ?string $message,
        public ?array $pause,
    ) {}

    public static function tryFromGatewayData(#[SensitiveParameter] mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }

        $pause = $data['pause'] ?? null;

        return new self(
            enabled: ($data['enabled'] ?? null) === true,
            gate: self::string($data['gate'] ?? null),
            warning: self::string($data['warning'] ?? null),
            result: self::string($data['result'] ?? null),
            commit: self::string($data['commit'] ?? null),
            head: self::string($data['head'] ?? null),
            since: self::string($data['since'] ?? null),
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            message: self::string($data['message'] ?? null),
            pause: is_array($pause) && is_string($pause['reason'] ?? null) && is_int($pause['deployment_id'] ?? null) && is_string($pause['paused_at'] ?? null)
                ? ['reason' => $pause['reason'], 'deployment_id' => $pause['deployment_id'], 'commit' => self::string($pause['commit'] ?? null), 'paused_at' => $pause['paused_at']]
                : null,
        );
    }

    /** @return AutoDeployRecord */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'gate' => $this->gate,
            'warning' => $this->warning,
            'result' => $this->result,
            'commit' => $this->commit,
            'head' => $this->head,
            'since' => $this->since,
            'error_code' => $this->errorCode,
            'message' => $this->message,
            'pause' => $this->pause,
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
