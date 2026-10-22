<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\SetInstancePathProxyData;
use App\Domain\Instances\PathProxy;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class SetInstancePathProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'process_id' => ['required', 'integer:strict', 'min:1', 'exists:processes,id'],
            'port' => ['required', 'integer:strict', 'between:1,65535'],
            'prefix' => ['sometimes', 'required', 'string', 'regex:'.PathProxy::PrefixPattern, 'not_in:/app,/apps,/__orbit'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['process_id', 'port', 'prefix']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function toData(): SetInstancePathProxyData
    {
        $data = $this->validated();
        assert(is_int($data['process_id']) && is_int($data['port']));
        $prefix = $data['prefix'] ?? '/powersync';
        assert(is_string($prefix));

        return new SetInstancePathProxyData($data['process_id'], $data['port'], $prefix);
    }
}
