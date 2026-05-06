<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Exceptions\PromptAborted;
use Closure;
use Laravel\Prompts\Prompt;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

trait HandlesPromptCancellation
{
    /**
     * @throws PromptAborted
     */
    protected function promptText(string $label, bool|string $required = false, mixed $validate = null): string
    {
        return $this->withPromptCancellation(fn (): string => text(label: $label, required: $required, validate: $validate));
    }

    /**
     * @throws PromptAborted
     */
    protected function promptConfirm(string $label, bool $default = true): bool
    {
        return $this->withPromptCancellation(fn (): bool => confirm($label, default: $default));
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $prompt
     * @return TResult
     *
     * @throws PromptAborted
     */
    private function withPromptCancellation(Closure $prompt): mixed
    {
        Prompt::cancelUsing(fn () => throw new PromptAborted);

        try {
            return $prompt();
        } finally {
            Prompt::cancelUsing(null);
        }
    }
}
