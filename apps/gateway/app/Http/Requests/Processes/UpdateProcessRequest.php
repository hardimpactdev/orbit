<?php

declare(strict_types=1);

namespace App\Http\Requests\Processes;

use App\Models\Node;
use App\Models\Process;
use Illuminate\Validation\Validator;

final class UpdateProcessRequest extends StoreProcessRequest
{
    /** @var list<string> */
    private array $immutableFields = [];

    protected function prepareForValidation(): void
    {
        foreach (['target_type', 'target_id', 'name'] as $field) {
            if ($this->exists($field)) {
                $this->immutableFields[] = $field;
            }
        }
        $process = $this->route('process');
        if ($process instanceof Process) {
            $this->merge([
                'target_type' => $process->owner_type === Node::class ? 'node' : 'instance',
                'target_id' => $process->owner_id,
                'name' => $process->name,
            ]);
        }
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            foreach ($this->immutableFields as $field) {
                $validator->errors()->add($field, 'The Process identity cannot be changed.');
            }
        }];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['preset'] = ['missing'];
        $rules['start'] = ['missing'];

        return $rules;
    }
}
