<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Data\Instances\InstancePublishInput;
use App\Domain\SourceControl\GitBranchName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

final class PublishInstanceRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $branch = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! GitBranchName::isValid($value)) {
                $fail('The branch name is invalid.');
            }
        };

        return [
            'commit' => ['required', 'string', 'regex:/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/'],
            'branch' => ['required', 'string', $branch],
            'pull_request' => ['sometimes', 'required', 'array:base,title,body'],
            'pull_request.base' => ['required_with:pull_request', 'string', $branch],
            'pull_request.title' => ['required_with:pull_request', 'string', 'max:256'],
            'pull_request.body' => ['present_with:pull_request', 'string', 'max:65536'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['commit', 'branch', 'pull_request']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function inputData(): InstancePublishInput
    {
        return InstancePublishInput::from($this->validated());
    }
}
