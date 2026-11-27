<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayRequestId;

final readonly class ToolPolicyResponse
{
    /** @param array<string, mixed>|null $document */
    public function __construct(public string $mode, public ?string $catalogPath, public ?int $snapshotId, public ?array $document, public ?string $requestId) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, ?string $requestId): self
    {
        $mode = $data['mode'] ?? null;
        $path = $data['catalog_path'] ?? null;
        $snapshot = $data['snapshot_id'] ?? null;
        $document = $data['document'] ?? null;
        if (! in_array($mode, ['file', 'database'], true) || ($path !== null && ! is_string($path))
            || ($snapshot !== null && (! is_int($snapshot) || $snapshot < 1))
            || ($document !== null && (! is_array($document) || array_is_list($document)))) {
            throw new InvalidArgumentException('Invalid Tool policy response.');
        }
        $redactor = new CredentialRedactor;
        $fields = [];
        foreach ($document ?? [] as $key => $value) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Invalid Tool policy document.');
            }
            $fields[$key] = $value;
        }

        return new self($mode, $path === null ? null : $redactor->redactText($path), $snapshot,
            $document === null ? null : $redactor->redactArray($fields), GatewayRequestId::fromTransport($requestId));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'catalog_path' => $this->catalogPath, 'snapshot_id' => $this->snapshotId,
            'document' => $this->document, 'request_id' => $this->requestId];
    }
}
