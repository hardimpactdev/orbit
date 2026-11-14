<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A thread that Conn started on a container server: the id T3 knows it by and its title. Longer titles are cut to 255 characters. */
final class RecordConnThreadRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'thread_id' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string'],
        ];
    }

    public function threadId(): string
    {
        return $this->string('thread_id')->toString();
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }
}
