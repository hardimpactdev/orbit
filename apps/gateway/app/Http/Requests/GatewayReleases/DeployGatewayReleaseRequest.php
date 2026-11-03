<?php

declare(strict_types=1);

namespace App\Http\Requests\GatewayReleases;

use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class DeployGatewayReleaseRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'commit' => ['required', 'string', 'regex:/\A[0-9a-f]{7,40}\z/D'],
            'swamp' => ['sometimes', 'boolean:strict'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['commit', 'swamp']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function commit(): string
    {
        return $this->string('commit')->toString();
    }

    /** Run the release as the `gateway.release` Swamp run instead of the release unit. */
    public function swamp(): bool
    {
        return $this->boolean('swamp');
    }
}
