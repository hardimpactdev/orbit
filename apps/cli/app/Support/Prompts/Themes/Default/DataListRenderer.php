<?php

declare(strict_types=1);

namespace App\Support\Prompts\Themes\Default;

use App\Support\Prompts\DataList;
use Laravel\Prompts\Themes\Default\Renderer;

final class DataListRenderer extends Renderer
{
    public function __invoke(DataList $dataList): string
    {
        foreach ($dataList->groups as $index => $group) {
            if ($index > 0) {
                $this->newLine();
            }

            $this->renderGroup($group);
        }

        return strval($this);
    }

    /**
     * @param  array{
     *     heading: string,
     *     items: list<array{
     *         label: string,
     *         properties: array<string, string>,
     *     }>,
     * }  $group
     */
    private function renderGroup(array $group): void
    {
        $this->line(" {$this->gray('┌')} {$this->cyan($group['heading'])}");

        foreach ($group['items'] as $itemIndex => $item) {
            if ($itemIndex > 0) {
                $this->line(" {$this->gray('│')}");
            }

            $this->line(" {$this->gray('│')} {$item['label']}");

            foreach ($item['properties'] as $label => $value) {
                $this->line(" {$this->gray('│')}   {$this->dim("{$label}:")} {$value}");
            }
        }

        $this->line(" {$this->gray('└')}");
    }
}
