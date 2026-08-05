<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class LocalApplicationLogOperationStream
{
    public string $operationUuid;

    public string $channel;

    public string $publishEndpoint;

    public string $stopDecisionEndpoint;

    public ?string $gatewayUrl;

    public ?string $caPemPath;

    public string $publisherToken;

    /**
     * @param  array{
     *     operation_uuid: string,
     *     channel: string,
     *     publish_endpoint: string,
     *     stop_decision_endpoint: string,
     *     gateway_url: ?string,
     *     ca_pem_path: ?string,
     *     publisher_token: string
     * }  $fields
     */
    private function __construct(array $fields)
    {
        $this->operationUuid = $fields['operation_uuid'];
        $this->channel = $fields['channel'];
        $this->publishEndpoint = $fields['publish_endpoint'];
        $this->stopDecisionEndpoint = $fields['stop_decision_endpoint'];
        $this->gatewayUrl = $fields['gateway_url'];
        $this->caPemPath = $fields['ca_pem_path'];
        $this->publisherToken = $fields['publisher_token'];
    }

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

        return new self(self::validatedFields($value));
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
