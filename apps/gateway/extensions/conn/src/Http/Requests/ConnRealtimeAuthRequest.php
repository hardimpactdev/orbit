<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Pusher fields of a Conn profile channel auth ([Profile events](/reference/conn#profile-events)). The body is not
 * trimmed, so the socket id is anchored at both ends: a trailing line feed is refused, not signed.
 */
final class ConnRealtimeAuthRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'socket_id' => ['required', 'string', 'regex:/\A\d+\.\d+\z/'],
            'channel_name' => ['required', 'string'],
        ];
    }

    public function socketId(): string
    {
        return $this->string('socket_id')->toString();
    }

    public function channelName(): string
    {
        return $this->string('channel_name')->toString();
    }
}
