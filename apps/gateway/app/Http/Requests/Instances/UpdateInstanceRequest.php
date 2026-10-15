<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Domain\Instances\Deployment\SharedPaths;
use App\Domain\SourceControl\GitBranchName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

final class UpdateInstanceRequest extends FormRequest
{
    private ?string $branch = null;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'branch' => ['sometimes', 'required', 'string'],
            'shared_paths' => ['sometimes', 'nullable', 'array', 'list', 'max:32'],
            'shared_paths.*' => ['string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $payload = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['branch', 'shared_paths']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        if ($payload === []) {
            throw ValidationException::withMessages(['body' => ['Provide branch or shared_paths.']]);
        }

        if (array_key_exists('branch', $payload) && ! is_string($payload['branch'])) {
            throw ValidationException::withMessages(['body' => ['The branch field must be a string.']]);
        }

        try {
            if (isset($payload['branch'])) {
                GitBranchName::validate($payload['branch']);
            }
            if (isset($payload['shared_paths'])) {
                $object = json_decode($this->getContent());
                if (! $object instanceof \stdClass || ! is_array($object->shared_paths ?? null)) {
                    throw new InvalidArgumentException;
                }
                SharedPaths::validate($payload['shared_paths']);
            }
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['body' => ['The deployment configuration is invalid.']]);
        }

        $this->branch = $payload['branch'] ?? null;

        return $payload;
    }

    /** @return list<string>|null */
    public function sharedPaths(): ?array
    {
        $paths = $this->validated('shared_paths');

        return $paths === null ? null : SharedPaths::validate($paths);
    }

    public function branch(): ?string
    {
        return $this->branch;
    }
}
