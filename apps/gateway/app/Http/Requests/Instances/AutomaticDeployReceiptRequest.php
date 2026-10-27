<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use Illuminate\Foundation\Http\FormRequest;

final class AutomaticDeployReceiptRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'commit' => ['required', 'string', 'regex:/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D'],
            'request_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,128}\z/D'],
        ];
    }

    public function commit(): string
    {
        $value = $this->validated('commit');
        assert(is_string($value));

        return $value;
    }

    public function requestKey(): string
    {
        $value = $this->validated('request_key');
        assert(is_string($value));

        return $value;
    }
}
