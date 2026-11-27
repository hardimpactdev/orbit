<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Tools;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class AptRepositoriesResponse
{
    /** @var list<AptRepositoryResponse> */
    public array $repositories;

    public string $requestId;

    /** @param array<array-key, mixed> $repositories */
    public function __construct(
        #[SensitiveParameter]
        array $repositories,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if (! array_is_list($repositories)) {
            throw new InvalidArgumentException('Invalid apt repository response collection.');
        }
        foreach ($repositories as $repository) {
            if (! $repository instanceof AptRepositoryResponse) {
                throw new InvalidArgumentException('Invalid apt repository response collection member.');
            }
        }

        $this->repositories = $repositories;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? '';
    }

    /** @return array{repositories: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'repositories' => array_map(static function (AptRepositoryResponse $repository): array {
                $data = $repository->toArray();
                unset($data['request_id']);

                return $data;
            }, $this->repositories),
            'request_id' => $this->requestId,
        ];
    }
}
