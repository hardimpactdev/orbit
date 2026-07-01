<?php

declare(strict_types=1);

namespace App\Support\Prompts;

use App\Support\Prompts\Themes\Default\DataListRenderer;
use Laravel\Prompts\Prompt;

final class DataList extends Prompt
{
    /**
     * @param  list<array{
     *     heading: string,
     *     items: list<array{
     *         label: string,
     *         properties: array<string, string>,
     *     }>,
     * }>  $groups
     */
    public function __construct(
        public array $groups,
    ) {}

    public function display(): void
    {
        $this->prompt();
    }

    public function prompt(): bool
    {
        $this->capturePreviousNewLines();

        $this->state = 'submit';

        static::output()->write($this->renderTheme());

        return true;
    }

    public function value(): bool
    {
        return true;
    }

    protected function getRenderer(): callable
    {
        return new DataListRenderer($this);
    }
}
