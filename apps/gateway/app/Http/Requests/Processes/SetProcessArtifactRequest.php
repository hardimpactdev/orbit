<?php

declare(strict_types=1);

namespace App\Http\Requests\Processes;

use Illuminate\Foundation\Http\FormRequest;

/** The release artifact of a systemd Process (docs/cli/process/artifact-set.mdx). A preset fills every field but the version. */
final class SetProcessArtifactRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'preset' => ['nullable', 'string', 'max:64'],
            'repository' => ['required_without:preset', 'nullable', 'string', 'max:200'],
            'asset' => ['required_without:preset', 'nullable', 'string', 'max:200'],
            'binary' => ['required_without:preset', 'nullable', 'string', 'max:200'],
            'checksums' => ['nullable', 'string', 'max:200'],
            'version_args' => ['nullable', 'array', 'max:8'],
            'version_args.*' => ['string', 'max:64'],
            'version' => ['nullable', 'string', 'max:65'],
        ];
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->safe()->only(['preset', 'repository', 'asset', 'binary', 'checksums', 'version_args', 'version']);
    }
}
