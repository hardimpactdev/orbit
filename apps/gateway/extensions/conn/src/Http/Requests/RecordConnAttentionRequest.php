<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Orbit\Extensions\Conn\Domain\Push\ConnPush;

/** One attention or attention_cleared event reported by a Conn Server. */
final class RecordConnAttentionRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'ulid'],
            'type' => ['required', 'string', Rule::in(ConnPush::EventTypes)],
            'kind' => ['nullable', 'string', Rule::in(ConnPush::AttentionKinds), 'required_if:type,attention', 'prohibited_if:type,attention_cleared'],
            'environment_id' => ['required', 'string', 'max:255'],
            'thread_id' => ['required', 'string', 'max:255'],
            'request_id' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'string', 'date'],
            'thread_title' => ['nullable', 'string', 'max:'.ConnPush::MaxTitleLength],
        ];
    }

    /**
     * @return array{
     *     event_id: string,
     *     type: string,
     *     kind: ?string,
     *     environment_id: string,
     *     thread_id: string,
     *     request_id: ?string,
     *     occurred_at: CarbonImmutable,
     *     thread_title: ?string
     * }
     */
    public function payload(): array
    {
        $kind = $this->input('kind');
        $requestId = $this->input('request_id');
        $threadTitle = $this->input('thread_title');

        return [
            'event_id' => $this->string('event_id')->toString(),
            'type' => $this->string('type')->toString(),
            'kind' => is_string($kind) ? $kind : null,
            'environment_id' => $this->string('environment_id')->toString(),
            'thread_id' => $this->string('thread_id')->toString(),
            'request_id' => is_string($requestId) ? $requestId : null,
            'occurred_at' => CarbonImmutable::parse($this->string('occurred_at')->toString()),
            'thread_title' => is_string($threadTitle) ? $threadTitle : null,
        ];
    }
}
