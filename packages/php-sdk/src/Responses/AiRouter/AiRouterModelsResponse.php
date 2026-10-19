<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AiRouter;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class AiRouterModelsResponse
{
    /** @var list<AiRouterModelResponse> */
    public array $models;

    public string $requestId;

    /**
     * @param  array<array-key, mixed>  $models
     */
    public function __construct(
        array $models,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if (! array_is_list($models)) {
            throw new InvalidArgumentException('Invalid ai-router model collection.');
        }

        foreach ($models as $model) {
            if (! $model instanceof AiRouterModelResponse) {
                throw new InvalidArgumentException('Invalid ai-router model collection member.');
            }
        }

        $this->models = $models;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? $requestId;
    }

    /**
     * @return array{
     *     models: list<array{id: string, provider: string}>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'models' => array_map(
                static function (AiRouterModelResponse $model): array {
                    $data = $model->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->models,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
