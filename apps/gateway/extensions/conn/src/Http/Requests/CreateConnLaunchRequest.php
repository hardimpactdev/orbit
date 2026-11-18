<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use App\Domain\Instances\InstanceName;
use App\Domain\SourceControl\GitBranchName;
use App\Support\StrictJson;
use App\Support\ValidatedData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use InvalidArgumentException;
use Orbit\Extensions\Conn\Data\ConnLaunchRequestData;
use stdClass;

final class CreateConnLaunchRequest extends FormRequest
{
    private const array Fields = ['expected_profile_id', 'expected_node_id', 'project_id', 'workspace_id', 'target',
        'prompt', 'model_selection', 'runtime_mode', 'interaction_mode', 'source', 'command_id', 'thread_id', 'message_id'];

    private stdClass $body;

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = $this->getContent();
        try {
            $body = StrictJson::decode($content, strlen($content), checkNumber: self::checkNumber(...));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['body' => ['The request must be a valid JSON object without duplicate keys.']]);
        }
        if (! $body instanceof stdClass || array_diff(array_keys(get_object_vars($body)), self::Fields) !== []) {
            throw ValidationException::withMessages(['body' => ['The request must be a JSON object with supported launch fields.']]);
        }
        $this->body = $body;
        $keys = $this->headers->all('Idempotency-Key');

        return [...StrictJson::associative($body), 'Idempotency-Key' => count($keys) === 1 ? $keys[0] : null];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'Idempotency-Key' => ['required', 'uuid'],
            'expected_profile_id' => ['required', 'integer:strict', 'min:1'],
            'expected_node_id' => ['required', 'integer:strict', 'min:1'],
            'project_id' => ['required', 'integer:strict', 'min:1'],
            'workspace_id' => ['nullable', 'string', 'min:1', 'max:100'],
            'target' => ['required', 'array:kind,name,branch,instance_id'],
            'target.kind' => ['required', 'in:new,existing'],
            'target.name' => ['exclude_unless:target.kind,new', ...InstanceName::rules()],
            'target.instance_id' => ['required_if:target.kind,existing', 'integer:strict', 'min:1'],
            'target.branch' => ['nullable', 'array:mode,from,name'],
            'target.branch.mode' => ['required_with:target.branch', 'in:new,existing'],
            'prompt' => ['present', 'string'],
            'model_selection' => ['sometimes', 'array'],
            'runtime_mode' => ['sometimes', 'required', 'in:approval-required,auto-accept-edits,auto,full-access'],
            'interaction_mode' => ['sometimes', 'required', 'in:default,plan'],
            'source' => ['required', 'in:web,mobile'],
            'command_id' => ['required', 'uuid'],
            'thread_id' => ['required', 'uuid'],
            'message_id' => ['required', 'uuid'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (($this->body->prompt ?? null) === '') {
                $validator->errors()->add('prompt', 'The first message must contain text.');
            }
            $target = $this->body->target ?? null;
            if (! $target instanceof stdClass) {
                return;
            }
            if (($target->kind ?? null) === 'existing') {
                foreach (['name', 'branch'] as $field) {
                    if (property_exists($target, $field)) {
                        $validator->errors()->add('target.'.$field, 'This field is not allowed on an existing target.');
                    }
                }
            } elseif (property_exists($target, 'instance_id')) {
                $validator->errors()->add('target.instance_id', 'This field is not allowed on a new target.');
            }
            $branch = $target->branch ?? null;
            if ($branch !== null) {
                $mode = $branch instanceof stdClass ? ($branch->mode ?? null) : null;
                if (! in_array($mode, ['new', 'existing'], true)) {
                    $validator->errors()->add('target.branch.mode', 'Choose a branch mode.');
                } else {
                    $field = $mode === 'new' ? 'from' : 'name';
                    $value = $branch->{$field} ?? null;
                    if (! is_string($value) || ! GitBranchName::isValid($value) || str_starts_with($value, 'refs/')) {
                        $validator->errors()->add('target.branch.'.$field, 'A short Git branch name without refs/ is required.');
                    }
                    if (property_exists($branch, $mode === 'new' ? 'name' : 'from')) {
                        $validator->errors()->add('target.branch', 'This field is not allowed in this mode.');
                    }
                    if ($mode === 'new' && is_string($target->name ?? null) && ! GitBranchName::isValid($target->name)) {
                        $validator->errors()->add('target.name', 'The derived Git branch name is invalid.');
                    }
                }
            }
            if (property_exists($this->body, 'model_selection')) {
                $selection = $this->body->model_selection;
                if (! $selection instanceof stdClass) {
                    $validator->errors()->add('model_selection', 'The model selection must be an object.');
                } elseif (! is_string($selection->model ?? null) || preg_match('/\S/u', $selection->model) !== 1) {
                    $validator->errors()->add('model_selection.model', 'The model name is required.');
                } elseif (! self::providerValid($selection)) {
                    $validator->errors()->add('model_selection.instanceId', 'The provider instance ID is invalid.');
                } elseif (! self::optionsValid($selection->options ?? null)) {
                    $validator->errors()->add('model_selection.options', 'The model options are invalid.');
                }
            }
        }];
    }

    private static function providerValid(stdClass $selection): bool
    {
        foreach (['instanceId', 'provider'] as $field) {
            if (property_exists($selection, $field)) {
                $value = $selection->{$field};

                return is_string($value) && preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/D', $value) === 1;
            }
        }

        return true; // Without a requested provider, resolve the workspace fallback.
    }

    private static function optionsValid(mixed $options): bool
    {
        if ($options === null || $options instanceof stdClass) {
            return true; // Stock T3 accepts a legacy options object.
        }

        return is_array($options) && array_all($options, static function (mixed $option): bool {
            if (! $option instanceof stdClass || ! is_string($option->id ?? null) || preg_match('/\S/u', $option->id) !== 1) {
                return false;
            }
            $value = $option->value ?? null;

            return is_bool($value) || (is_string($value) && preg_match('/\S/u', $value) === 1);
        });
    }

    private static function checkNumber(string $token): void
    {
        $value = json_decode($token, flags: JSON_THROW_ON_ERROR);
        if ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || abs($value) > 9_007_199_254_740_991) {
            self::refuseNumber();
        }
        $original = self::decimalValue($token);
        if ($original === null || $original !== self::decimalValue(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))) {
            self::refuseNumber();
        }
    }

    /** @return array{string, int}|null */
    private static function decimalValue(string $token): ?array
    {
        if (preg_match('/\A(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?\z/D', $token, $parts) !== 1) {
            return null;
        }
        $fraction = $parts[3] ?? '';
        $digits = ltrim($parts[2].$fraction, '0');
        if ($digits === '') {
            return ['0', 0];
        }
        $significand = rtrim($digits, '0');
        $encodedExponent = $parts[4] ?? '0';
        $magnitude = ltrim($encodedExponent, '+-0');
        if (strlen($magnitude) >= strlen((string) PHP_INT_MAX)) {
            return null;
        }
        $exponent = (int) $magnitude * (str_starts_with($encodedExponent, '-') ? -1 : 1);

        return [$parts[1].$significand, $exponent - strlen($fraction) + strlen($digits) - strlen($significand)];
    }

    private static function refuseNumber(): never
    {
        throw ValidationException::withMessages(['body' => ['Request numbers must be finite, within the JavaScript safe integer range, and preserve their value.']]);
    }

    public function payload(): ConnLaunchRequestData
    {
        $body = $this->body;
        assert($body->target instanceof stdClass);
        $body->workspace_id ??= null;
        if ($body->target->kind === 'new') {
            $body->target->branch ??= null;
        }

        return new ConnLaunchRequestData(
            ValidatedData::string($this->validated('Idempotency-Key')),
            ValidatedData::integer($body->expected_profile_id),
            ValidatedData::integer($body->expected_node_id),
            ValidatedData::integer($body->project_id),
            ValidatedData::nullableString($body->workspace_id),
            ValidatedData::string($body->target->kind),
            ValidatedData::nullableString($body->target->name ?? null),
            isset($body->target->instance_id) ? ValidatedData::integer($body->target->instance_id) : null,
            ValidatedData::string($body->source),
            $body,
        );
    }
}
