<?php

declare(strict_types=1);

namespace App\Http\Requests\Tasks;

use App\Data\Tasks\TaskWorkerData;
use App\Http\Requests\TopLevelJsonObjectInspector;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

class StartTaskProvisionRequest extends FormRequest
{
    protected function workerPresence(): string
    {
        return 'sometimes';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'worker' => [$this->workerPresence(), 'array:orchestrator_url,enrollment_token,server_token,labels', 'required_array_keys:orchestrator_url,enrollment_token'],
            'worker.orchestrator_url' => ['required_with:worker', 'string', 'max:2048', 'regex:#\Awss://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?(?:/[a-zA-Z0-9._~!$&\x27()*+,;=:@/-]*)?\z#D'],
            'worker.enrollment_token' => ['required_with:worker', 'string', 'max:4096', 'regex:/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{16,}\z/D'],
            'worker.server_token' => ['sometimes', 'string', 'max:4096', 'regex:/\A[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{16,}\z/D'],
            'worker.labels' => ['sometimes', 'array', 'max:32'],
            'worker.labels.*' => ['string', 'max:128', 'regex:/\A[A-Za-z0-9_.-]+\z/D'],
        ];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $content = trim($this->getContent());
        if ($content === '' || $content === '[]') {
            return [];
        }
        try {
            $input = app(TopLevelJsonObjectInspector::class)->inspect($content, ['worker']);
            $worker = $input['worker'] ?? null;
            $labels = is_array($worker) ? ($worker['labels'] ?? []) : [];
            if (is_array($labels)) {
                foreach (array_keys($labels) as $label) {
                    if (! is_string($label) || preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/D', $label) !== 1) {
                        throw new UnexpectedValueException('Worker label names must contain letters, digits, dots, underscores, or hyphens.');
                    }
                }
            }

            return $input;
        } catch (UnexpectedValueException $exception) {
            throw ValidationException::withMessages(['body' => [$exception->getMessage()]]);
        }
    }

    public function worker(): ?TaskWorkerData
    {
        $worker = $this->validated('worker');

        return $worker === null ? null : TaskWorkerData::from($worker);
    }
}
