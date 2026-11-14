<?php

declare(strict_types=1);

namespace App\Http\Requests\Processes;

use Illuminate\Foundation\Http\FormRequest;

/** `instance` names the Instance of a container Process, which its destroy requires (docs/cli/process/destroy.mdx#a-process-in-an-instance-container). */
final class DestroyProcessRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'instance' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function instanceId(): ?int
    {
        return $this->has('instance') ? $this->integer('instance') : null;
    }
}
