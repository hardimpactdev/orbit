<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\StartTaskPublishData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class StartTaskPublishRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['check' => ['required', 'integer:strict', 'min:1'], 'pull_request' => ['sometimes', 'required', 'array:title,body'], 'pull_request.title' => ['required_with:pull_request', 'string', 'max:256'], 'pull_request.body' => ['required_with:pull_request', 'string', 'max:65536']];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['check', 'pull_request']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): StartTaskPublishData
    {
        return StartTaskPublishData::from($this->validated());
    }
}
