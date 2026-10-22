<?php

declare(strict_types=1);

namespace App\Http\Requests\WebSocket;

use Illuminate\Foundation\Http\FormRequest;

final class RuntimeBootRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['boot_id' => ['required', 'uuid']];
    }
}
