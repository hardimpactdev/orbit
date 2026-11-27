<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\CredentialRedactor;
use Orbit\Sdk\Support\GatewayErrorCode;
use Orbit\Sdk\Support\GatewayRequestId;
use Orbit\Sdk\Support\ToolRepositoryData;
use SensitiveParameter;

/** A Node's apt repository: its definition, its own lifecycle status and how many Tools reference it. */
final readonly class AptRepositoryResponse
{
    public int $id;

    public int $nodeId;

    public string $name;

    /** @var array<string, mixed> the definition without its name */
    public array $definition;

    public string $status;

    public ?string $errorCode;

    public int $toolCount;

    public string $requestId;

    private const string NAME_PATTERN = '/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/D';

    private const array STATUSES = ['adding', 'ready', 'removing', 'failed'];

    /** @param array<string, mixed> $definition */
    public function __construct(
        int $id,
        int $nodeId,
        #[SensitiveParameter]
        string $name,
        #[SensitiveParameter]
        array $definition,
        #[SensitiveParameter]
        string $status,
        #[SensitiveParameter]
        ?string $errorCode,
        int $toolCount,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if ($id < 1 || $nodeId < 1 || $toolCount < 0) {
            throw new InvalidArgumentException('Invalid apt repository response identifier.');
        }
        if (strlen($name) > 63 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Invalid apt repository response field [name].');
        }
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Invalid apt repository response field [status].');
        }

        $this->id = $id;
        $this->nodeId = $nodeId;
        $this->name = $name;
        $this->definition = new CredentialRedactor()->redactArray($definition);
        $this->status = $status;
        $this->errorCode = GatewayErrorCode::fromTransport($errorCode);
        $this->toolCount = $toolCount;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        foreach (['id', 'node_id', 'tool_count'] as $key) {
            if (! is_int($data[$key] ?? null)) {
                throw new InvalidArgumentException("Invalid apt repository response field [{$key}].");
            }
        }
        foreach (['name', 'status'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new InvalidArgumentException("Invalid apt repository response field [{$key}].");
            }
        }
        $definition = ToolRepositoryData::fromGateway($data['definition'] ?? null)
            ?? throw new InvalidArgumentException('Invalid apt repository response field [definition].');
        $errorCode = $data['error_code'] ?? null;

        return new self($data['id'], $data['node_id'], $data['name'], $definition, $data['status'], is_string($errorCode) ? $errorCode : null,
            $data['tool_count'], $requestId);
    }

    /** @return array{id: int, node_id: int, name: string, definition: array<string, mixed>, status: string, error_code: ?string, tool_count: int, request_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'definition' => $this->definition,
            'status' => $this->status,
            'error_code' => $this->errorCode,
            'tool_count' => $this->toolCount,
            'request_id' => $this->requestId,
        ];
    }
}
