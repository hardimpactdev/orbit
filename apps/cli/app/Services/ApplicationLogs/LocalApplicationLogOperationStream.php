<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

use SensitiveParameter;

final readonly class LocalApplicationLogOperationStream
{
    public function __construct(
        public string $operationUuid,
        public string $channel,
        public string $publishEndpoint,
        public string $stopDecisionEndpoint,
        public ?string $gatewayUrl,
        public ?string $caPemPath,
        #[SensitiveParameter]
        public string $publisherToken,
    ) {}

    public static function from(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new LocalApplicationLogFailure(
                errorCode: 'validation_failed',
                message: 'Application log operation stream metadata is invalid.',
                meta: ['field' => 'operation_stream'],
            );
        }

        $fields = self::validatedFields($value);

        return new self(
            operationUuid: $fields['operation_uuid'],
            channel: $fields['channel'],
            publishEndpoint: $fields['publish_endpoint'],
            stopDecisionEndpoint: $fields['stop_decision_endpoint'],
            gatewayUrl: $fields['gateway_url'],
            caPemPath: $fields['ca_pem_path'],
            publisherToken: $fields['publisher_token'],
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array{
     *     operation_uuid: string,
     *     channel: string,
     *     publish_endpoint: string,
     *     stop_decision_endpoint: string,
     *     gateway_url: ?string,
     *     ca_pem_path: ?string,
     *     publisher_token: string
     * }
     */
    private static function validatedFields(array $value): array
    {
        return [
            'operation_uuid' => self::requiredString($value, 'operation_uuid'),
            'channel' => self::requiredString($value, 'channel'),
            'publish_endpoint' => self::requiredString($value, 'publish_endpoint'),
            'stop_decision_endpoint' => self::requiredString($value, 'stop_decision_endpoint'),
            'gateway_url' => self::optionalString($value, 'gateway_url'),
            'ca_pem_path' => self::optionalString($value, 'ca_pem_path'),
            'publisher_token' => self::requiredString($value, 'publisher_token'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function requiredString(array $value, string $key): string
    {
        if (is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
            return trim($value[$key]);
        }

        throw new LocalApplicationLogFailure(
            errorCode: 'validation_failed',
            message: 'Application log operation stream metadata is invalid.',
            meta: ['field' => "operation_stream.{$key}"],
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function optionalString(array $value, string $key): ?string
    {
        if (! is_string($value[$key] ?? null)) {
            return null;
        }

        $trimmed = trim($value[$key]);

        return $trimmed === '' ? null : $trimmed;
    }
}
