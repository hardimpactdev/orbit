<?php

declare(strict_types=1);

namespace App\Http\Gateway\Requests\Schedules;

use App\Http\Gateway\GatewayRequest;
use App\Http\Gateway\Responses\Schedules\ScheduleRunResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class StoreScheduleRunRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $scheduleKey,
        public readonly string $status,
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly string $startedAt,
        public readonly ?string $finishedAt = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/schedules/runs';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultBody(): array
    {
        return [
            'schedule_key' => $this->scheduleKey,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
        ];
    }

    public function createDtoFromResponse(Response $response): ScheduleRunResponse
    {
        $body = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        $success = is_array($body) ? ($body['success'] ?? []) : [];
        $data = is_array($success) ? ($success['data'] ?? []) : [];
        $run = is_array($data) ? ($data['run'] ?? []) : [];

        return new ScheduleRunResponse(
            run: is_array($run) ? $run : [],
        );
    }
}
