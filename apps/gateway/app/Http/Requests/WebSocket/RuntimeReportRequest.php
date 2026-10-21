<?php

declare(strict_types=1);

namespace App\Http\Requests\WebSocket;

use Illuminate\Foundation\Http\FormRequest;

final class RuntimeReportRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'boot_id' => ['required', 'uuid'], 'epoch' => ['required', 'uuid'],
            'revision' => ['required', 'integer', 'min:1'], 'sequence' => ['required', 'integer', 'min:1'],
            'state' => ['required', 'in:starting,serving'], 'app_count' => ['required', 'integer', 'min:0', 'max:10000'],
            'connections' => ['present', 'array', 'max:10000'], 'connections.*' => ['integer', 'min:0'],
            'messages' => ['present', 'array', 'max:10000'], 'messages.*' => ['array:received_messages,sent_messages,received_bytes,sent_bytes'],
            'messages.*.*' => ['integer', 'min:0'],
            'error' => ['nullable', 'in:registry.unreachable,registry.apply_failed,registry.registration_failed'],
        ];
    }
}
