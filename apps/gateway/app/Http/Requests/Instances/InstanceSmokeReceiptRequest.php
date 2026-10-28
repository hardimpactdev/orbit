<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use Illuminate\Foundation\Http\FormRequest;

final class InstanceSmokeReceiptRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['request_key' => ['required', 'string', 'regex:/\A[A-Za-z0-9._:-]{1,128}\z/D']];
    }

    public function requestKey(): string
    {
        $key = $this->validated('request_key');
        assert(is_string($key));

        return $key;
    }
}
