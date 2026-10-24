<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\StartTaskAnalysisData;
use App\Domain\Tasks\TaskAnalysisPlan;
use App\Support\StrictJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class StartTaskAnalysisRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $oid = 'regex:/\A(?:[a-f0-9]{40}|[a-f0-9]{64})\z/D';

        return ['subtask' => ['required', 'integer:strict', 'min:1'], 'plan' => ['required', 'array'],
            'plan_sha256' => ['required', 'string', 'regex:'.TaskAnalysisPlan::DigestPattern],
            'base' => ['required', 'string', $oid], 'head' => ['required', 'string', $oid], 'tree' => ['required', 'string', $oid]];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        try {
            $object = StrictJson::decode($this->getContent(), TaskAnalysisPlan::RequestBytes);
            $fields = StrictJson::fields($object, ['subtask', 'plan', 'plan_sha256', 'base', 'head', 'tree']);
            if (! is_string($fields['plan_sha256'])) {
                throw new InvalidArgumentException('The plan digest must be a string.');
            }
            TaskAnalysisPlan::parse($fields['plan'], $fields['plan_sha256']);

            return StrictJson::associative($object);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function payload(): StartTaskAnalysisData
    {
        return StartTaskAnalysisData::from($this->validated());
    }
}
