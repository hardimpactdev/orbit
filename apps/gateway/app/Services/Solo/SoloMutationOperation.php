<?php

declare(strict_types=1);

namespace App\Services\Solo;

use Illuminate\Http\Request;

/**
 * @mago-expect lint:excessive-parameter-list
 */
final readonly class SoloMutationOperation
{
    public function __construct(
        public string $apiPath,
        public string $method,
        public string $permission,
        public string $upstreamTemplate,
        public string $dataKey,
        /** @var list<string> */
        public array $requiredFields = [],
        /** @var array<string, string> */
        public array $payloadFields = [],
    ) {}

    public function upstreamPath(Request $request): string
    {
        $path = $this->upstreamTemplate;

        foreach ($this->requiredFields as $field) {
            $path = str_replace('{'.$field.'}', rawurlencode($this->requiredString($request, $field)), $path);
        }

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function upstreamPayload(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = [];

        foreach ($this->payloadFields as $requestField => $payloadField) {
            $value = $request->input($requestField);

            if ($value === null || $value === '') {
                if ($this->payloadFieldIsOptional($requestField)) {
                    continue;
                }

                throw new SoloProxyException(
                    errorCode: 'validation_failed',
                    message: "The {$requestField} field is required.",
                    meta: ['field' => $requestField],
                    status: 422,
                );
            }

            $payload[$payloadField] = $value;
        }

        return $payload;
    }

    private function requiredString(Request $request, string $field): string
    {
        $value = $request->input($field);

        if (! is_scalar($value) || (string) $value === '') {
            throw new SoloProxyException(
                errorCode: 'validation_failed',
                message: "The {$field} field is required.",
                meta: ['field' => $field],
                status: 422,
            );
        }

        return (string) $value;
    }

    private function payloadFieldIsOptional(string $field): bool
    {
        return in_array($field, ['body', 'expected_revision', 'project'], strict: true);
    }
}
