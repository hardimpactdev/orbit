<?php

declare(strict_types=1);

namespace App\Http\Requests\Processes;

use Illuminate\Foundation\Http\FormRequest;

/** `version` may carry a leading `v` (docs/cli/process/upgrade.mdx). */
final class UpgradeProcessRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:65'],
        ];
    }

    public function version(): string
    {
        return $this->string('version')->toString();
    }
}
