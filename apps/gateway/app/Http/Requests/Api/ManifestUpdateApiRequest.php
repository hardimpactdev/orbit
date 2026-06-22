<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Http\Requests\Api\Concerns\HandlesOrbitApiValidationFailure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ManifestUpdateApiRequest extends FormRequest
{
    use HandlesOrbitApiValidationFailure;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'filled', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $url = $this->input('url');

            if (! is_string($url) || trim($url) === '') {
                return;
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);

            if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                $validator->errors()->add('url', 'Manifest URL must be an http or https URL.');

                return;
            }

            if (filter_var($url, FILTER_VALIDATE_URL) === false) {
                $validator->errors()->add('url', 'Manifest URL must be a valid URL.');
            }
        });
    }

    #[\Override]
    public function url(): string
    {
        return trim((string) $this->input('url'));
    }

    protected function validationFailureFields(): array
    {
        return ['url'];
    }

    protected function validationMessageFor(string $field): string
    {
        return match ($field) {
            'url' => 'Manifest URL must be an http or https URL.',
            default => 'Validation failed.',
        };
    }
}
