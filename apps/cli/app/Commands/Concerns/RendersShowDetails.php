<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

trait RendersShowDetails
{
    /**
     * @param  array<string, mixed>  $properties
     */
    protected function renderShowDetails(string $title, array $properties): void
    {
        $labels = array_keys($properties);
        $width = max(array_map(mb_strlen(...), $labels));
        $last = count($properties) - 1;

        $this->newLine();
        $this->line("┌  {$title}");
        $this->line('│');

        foreach (array_values($properties) as $index => $value) {
            $label = $labels[$index];
            $prefix = $index === $last ? '└' : '├';
            $this->line($prefix.'  '.str_pad($label, $width + 3).$this->showDetailValue($value));

            if ($index !== $last) {
                $this->line('│');
            }
        }

        $this->newLine();
    }

    protected function showDetailValue(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value) && array_is_list($value)) {
            $values = array_values(array_filter(
                array_map(fn (mixed $item): string => $this->showDetailValue($item), $value),
                static fn (string $item): bool => $item !== '—',
            ));

            return $values === [] ? '—' : implode(', ', $values);
        }

        $json = json_encode($value);

        return is_string($json) ? $json : '—';
    }
}
