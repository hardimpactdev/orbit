<?php

declare(strict_types=1);

namespace App\Http\Requests\Instances;

use App\Domain\SourceControl\GitBranchName;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use UnexpectedValueException;

final class UpdateInstanceRequest extends FormRequest
{
    private ?string $branch = null;

    private ?bool $autoDeploy = null;

    private bool $validated = false;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'branch' => ['sometimes', 'required', 'string'],
            'auto_deploy' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $payload = app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), ['branch', 'auto_deploy']);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }

        if (! array_key_exists('branch', $payload) && ! array_key_exists('auto_deploy', $payload)) {
            throw ValidationException::withMessages(['body' => ['Provide branch, auto_deploy, or both.']]);
        }

        if (array_key_exists('branch', $payload)) {
            if (! is_string($payload['branch'])) {
                throw ValidationException::withMessages(['body' => ['The branch field must be a string.']]);
            }

            try {
                $this->branch = GitBranchName::validate($payload['branch']);
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(['body' => ['The branch is invalid.']]);
            }
        }

        if (array_key_exists('auto_deploy', $payload)) {
            if (! is_bool($payload['auto_deploy'])) {
                throw ValidationException::withMessages(['body' => ['The auto_deploy field must be true or false.']]);
            }

            $this->autoDeploy = $payload['auto_deploy'];
        }

        $this->validated = true;

        return $payload;
    }

    /** The new deployment branch, or null to keep it. */
    public function branch(): ?string
    {
        $this->assertValidated();

        return $this->branch;
    }

    /** Whether to deploy the Instance automatically, or null to keep the setting. */
    public function autoDeploy(): ?bool
    {
        $this->assertValidated();

        return $this->autoDeploy;
    }

    private function assertValidated(): void
    {
        if (! $this->validated) {
            throw new UnexpectedValueException('The Instance update was not validated.');
        }
    }
}
