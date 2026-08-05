<?php

declare(strict_types=1);

use App\Contracts\Loggable;
use App\Enums\ActivityLogType;
use App\Services\ActivityLogCorrelation;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Security\SecretSummaryRedactor;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $properties
 */
function activity_logger_boundary_loggable(
    array $properties,
    ?string $description = null,
): Loggable {
    return new class($properties, $description) implements Loggable {
        /**
         * @param  array<string, mixed>  $properties
         */
        public function __construct(
            private readonly array $properties,
            private readonly ?string $description,
        ) {}

        public function effect(): ActivityLogType
        {
            return ActivityLogType::Write;
        }

        public function type(): string
        {
            return 'activity.boundary.probe';
        }

        public function subject(): ?Model
        {
            return null;
        }

        public function properties(): array
        {
            return $this->properties;
        }

        public function description(): ?string
        {
            return $this->description;
        }
    };
}

describe(ActivityLogger::class, function (): void {
    it('redacts nested APP_KEY password and token material at the log chokepoint', function (): void {
        $appKeyMaterial = 'base64:ActivityLoggerMustNeverPersistThis==';
        $passwordMaterial = 'super-secret-password-value';
        $tokenMaterial = 'activity-boundary-token-value';
        $logger = new ActivityLogger(new ActivityLogCorrelation);
        $marker = SecretSummaryRedactor::REDACTED;

        $logger->log(
            activity_logger_boundary_loggable(
                properties: [
                    'status' => 'ok',
                    'nested' => [
                        'APP_KEY' => $appKeyMaterial,
                        'password' => $passwordMaterial,
                        'token' => $tokenMaterial,
                        'deeper' => [
                            'api_token' => $tokenMaterial,
                            'message' => "export APP_KEY={$appKeyMaterial}",
                        ],
                    ],
                    'command_line' => "orbit doctor --password={$passwordMaterial} APP_KEY={$appKeyMaterial}",
                ],
                description: "failed with password={$passwordMaterial} and APP_KEY={$appKeyMaterial}",
            ),
            channel: 'api',
            causer: null,
            extraProperties: [
                'stdout_summary' => "token={$tokenMaterial}",
            ],
        );

        $entry = Activity::query()->latest('id')->first();
        expect($entry)->not->toBeNull();

        /** @var array<string, mixed> $properties */
        $properties = $entry->properties->toArray();
        $blob = json_encode([
            'description' => $entry->description,
            'properties' => $properties,
        ], JSON_THROW_ON_ERROR);

        expect($properties['type'] ?? null)
            ->toBe('write')
            ->and($properties['status'] ?? null)
            ->toBe('ok')
            ->and(data_get(target: $properties, key: 'nested.APP_KEY'))
            ->toBe($marker)
            ->and(data_get(target: $properties, key: 'nested.password'))
            ->toBe($marker)
            ->and(data_get(target: $properties, key: 'nested.token'))
            ->toBe($marker)
            ->and(data_get(target: $properties, key: 'nested.deeper.api_token'))
            ->toBe($marker)
            ->and(data_get(target: $properties, key: 'nested.deeper.message'))
            ->toBe("export APP_KEY={$marker}")
            ->and($properties['command_line'] ?? null)
            ->toBe("orbit doctor --password={$marker} APP_KEY={$marker}")
            ->and($properties['stdout_summary'] ?? null)
            ->toBe("token={$marker}")
            ->and($entry->description)
            ->toBe("failed with password={$marker} and APP_KEY={$marker}")
            ->and($blob)
            ->not->toContain($appKeyMaterial)
            ->not->toContain($passwordMaterial)
            ->not->toContain($tokenMaterial);
    });

    it('preserves ordinary prose and non-secret keys through the log chokepoint', function (): void {
        $logger = new ActivityLogger(new ActivityLogCorrelation);

        $logger->log(
            activity_logger_boundary_loggable(
                properties: [
                    'status' => 'present',
                    'token_count' => 3,
                    'secretary_note' => 'The secretary shared status=ok with Version 0.1.190',
                    'public_key' => 'peer-public-key-probe',
                    'message' => 'password policy requires rotation',
                ],
                description: 'The secretary shared status=ok with Version 0.1.190',
            ),
            channel: 'api',
            causer: null,
        );

        $entry = Activity::query()->latest('id')->first();
        expect($entry)->not->toBeNull();

        /** @var array<string, mixed> $properties */
        $properties = $entry->properties->toArray();

        expect($properties)
            ->toMatchArray([
                'type' => 'write',
                'status' => 'present',
                'token_count' => 3,
                'secretary_note' => 'The secretary shared status=ok with Version 0.1.190',
                'public_key' => 'peer-public-key-probe',
                'message' => 'password policy requires rotation',
            ])
            ->and($entry->description)
            ->toBe('The secretary shared status=ok with Version 0.1.190');
    });
});
