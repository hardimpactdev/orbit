<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Domain\Projects\DevelopmentDeployStep;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

/** Reports a development deploy step's `inputs` against the domain rule under the `inputs` field. */
final class DevelopmentDeployStepInputs
{
    public static function check(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            $inputs = $validator->getData()['inputs'] ?? null;
            if (! is_array($inputs) || $validator->errors()->has('inputs')) {
                return;
            }
            try {
                DevelopmentDeployStep::validInputs($inputs);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('inputs', $exception->getMessage());
            }
        });
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<string>|null
     */
    public static function from(array $payload): ?array
    {
        $inputs = $payload['inputs'] ?? null;
        if (! is_array($inputs)) {
            return null;
        }

        return DevelopmentDeployStep::validInputs($inputs);
    }
}
