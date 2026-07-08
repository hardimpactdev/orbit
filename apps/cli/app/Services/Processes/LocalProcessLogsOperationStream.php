<?php

declare(strict_types=1);

namespace App\Services\Processes;

use SensitiveParameter;

final readonly class LocalProcessLogsOperationStream
{
    /**
     * @mago-expect lint:excessive-parameter-list
     */
    private function __construct(
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
            throw new LocalProcessLogsFailure(
                errorCode: 'validation_failed',
                message: 'Process logs operation stream metadata is invalid.',
                meta: ['field' => 'operation_stream'],
            );
        }

        return new self(
            operationUuid: self::requiredString($value, 'operation_uuid'),
            channel: self::requiredString($value, 'channel'),
            publishEndpoint: self::requiredString($value, 'publish_endpoint'),
            stopDecisionEndpoint: self::requiredString($value, 'stop_decision_endpoint'),
            gatewayUrl: self::optionalString($value, 'gateway_url'),
            caPemPath: self::optionalString($value, 'ca_pem_path'),
            publisherToken: self::requiredString($value, 'publisher_token'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function requiredString(array $value, string $key): string
    {
        if (is_string($value[$key] ?? null) && trim($value[$key]) !== '') {
            return trim($value[$key]);
        }

        throw new LocalProcessLogsFailure(
            errorCode: 'validation_failed',
            message: 'Process logs operation stream metadata is invalid.',
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
