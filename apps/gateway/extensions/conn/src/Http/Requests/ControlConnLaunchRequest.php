<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Http\Requests\TopLevelJsonObjectInspector;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Orbit\Extensions\Conn\Domain\ConnLaunchAccess;
use UnexpectedValueException;

final class ControlConnLaunchRequest extends FormRequest
{
    public function authorize(ConnLaunchAccess $access): bool
    {
        $caller = $this->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');
        $profileId = $access->profileId($caller);
        abort_unless($profileId !== null && $access->visible($caller, $profileId)->whereKey($this->operationId())->exists(), 404);

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            return app(TopLevelJsonObjectInspector::class)->inspect($this->getContent(), $this->routeIs('conn:launch:retry') ? ['target'] : []);
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return $this->routeIs('conn:launch:retry') ? ['target' => ['required', 'string', 'in:launch,application']] : [];
    }

    public function target(): string
    {
        return ValidatedData::string($this->validated('target'));
    }

    public function operationId(): int
    {
        return ConnLaunchAccess::operationId($this->route('operation_id'));
    }
}
